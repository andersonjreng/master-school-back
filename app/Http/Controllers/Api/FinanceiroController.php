<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\FinanceiroHelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/financeiro/get_configuracoes.php e api/financeiro/get_dashboard.php.
 */
class FinanceiroController extends Controller
{
    public function __construct(private FinanceiroHelperService $helper)
    {
    }

    public function configuracoes(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->helper->carregarConfiguracoes()]);
    }

    public function dashboard(): JsonResponse
    {
        DB::statement("UPDATE parcelas SET status = 'vencido' WHERE status = 'pendente' AND data_vencimento < CURDATE()");

        $mesAtualInicio = now()->startOfMonth()->toDateString();
        $mesAtualFim = now()->endOfMonth()->toDateString();

        $totalPrevisto = (float) DB::table('parcelas')
            ->whereBetween('data_vencimento', [$mesAtualInicio, $mesAtualFim])
            ->where('status', '!=', 'cancelado')
            ->sum('valor_final');

        $totalRecebido = (float) DB::table('parcelas')
            ->where('status', 'pago')
            ->whereBetween('data_pagamento', [$mesAtualInicio, $mesAtualFim])
            ->sum('valor_pago');

        $totalAberto = (float) DB::table('parcelas')
            ->whereIn('status', ['pendente', 'vencido'])
            ->where('data_vencimento', '<=', $mesAtualFim)
            ->sum('valor_final');

        $baseInadimp = (float) DB::table('parcelas')
            ->whereBetween('data_vencimento', [$mesAtualInicio, $mesAtualFim])
            ->whereNotIn('status', ['cancelado', 'negociado'])
            ->sum('valor_final');

        $vencidoMes = (float) DB::table('parcelas')
            ->whereBetween('data_vencimento', [$mesAtualInicio, $mesAtualFim])
            ->where('status', 'vencido')
            ->sum('valor_final');

        $taxaInadimp = $baseInadimp > 0 ? round(($vencidoMes / $baseInadimp) * 100, 1) : 0;

        // Série mensal dos últimos 12 meses (recebido por mês) — gera todos os 12
        // meses mesmo sem dados, igual ao original.
        $serieRows = DB::table('parcelas')
            ->select(DB::raw("DATE_FORMAT(data_pagamento, '%Y-%m') AS mes"), DB::raw('COALESCE(SUM(valor_pago), 0) AS recebido'))
            ->where('status', 'pago')
            ->where('data_pagamento', '>=', now()->subMonths(11)->startOfDay())
            ->groupBy(DB::raw("DATE_FORMAT(data_pagamento, '%Y-%m')"))
            ->get();

        $serieMap = [];
        foreach ($serieRows as $row) {
            $serieMap[$row->mes] = (float) $row->recebido;
        }

        $mesesPt = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        $labels = [];
        $valores = [];
        for ($i = 11; $i >= 0; $i--) {
            $data = now()->subMonths($i);
            $key = $data->format('Y-m');
            $labels[] = $mesesPt[$data->month - 1] . '/' . $data->format('y');
            $valores[] = $serieMap[$key] ?? 0;
        }

        return response()->json([
            'success' => true,
            'total_previsto' => $totalPrevisto,
            'total_recebido' => $totalRecebido,
            'total_aberto' => $totalAberto,
            'taxa_inadimp' => $taxaInadimp,
            'serie_mensal' => [
                'labels' => $labels,
                'valores' => $valores,
            ],
        ]);
    }
}
