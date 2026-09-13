<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/agent/max-uso.php e max-uso-admin.php.
 */
class MaxUsageController extends Controller
{
    public function meuUso(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        $funcao = $this->funcaoPrincipal($funcoes);
        $mesAtual = now()->format('Y-m');

        $limite = (int) (DB::table('max_limites')->where('funcao', $funcao)->value('limite_mensal') ?? 20);

        $uso = DB::table('max_uso')
            ->where('usuario_id', $usuarioId)
            ->where('mes_ano', $mesAtual)
            ->first();

        $usoAtual = $uso ? (int) $uso->total_mensagens : 0;
        $custoEstimado = $uso ? (float) $uso->custo_estimado : 0.0;

        return response()->json([
            'success' => true,
            'data' => [
                'uso_atual'      => $usoAtual,
                'limite'         => $limite,
                'restantes'      => max(0, $limite - $usoAtual),
                'percentual'     => $limite > 0 ? round(($usoAtual / $limite) * 100) : 0,
                'funcao'         => $funcao,
                'mes_ano'        => $mesAtual,
                'renovacao'      => now()->addMonthNoOverflow()->startOfMonth()->format('d/m/Y'),
                'custo_estimado' => round($custoEstimado, 6),
            ],
        ]);
    }

    public function usoAdmin(): JsonResponse
    {
        $mesAtual = now()->format('Y-m');

        $linhas = DB::select("
            SELECT
                COALESCE(u.nome_completo, CONCAT('Usuário #', mu.usuario_id)) AS nome,
                mu.total_mensagens,
                mu.custo_estimado,
                COALESCE(roles.funcao, 'Responsavel') AS funcao,
                COALESCE(ml.limite_mensal, 20) AS limite_mensal
            FROM max_uso mu
            LEFT JOIN usuarios u ON u.id = mu.usuario_id
            LEFT JOIN (
                SELECT
                    uf.usuario_id,
                    CASE
                        WHEN MAX(CASE WHEN f.nome_funcao IN ('Administrador','Diretoria') THEN 1 ELSE 0 END) = 1 THEN 'Administrador'
                        WHEN MAX(CASE WHEN f.nome_funcao = 'Professor' THEN 1 ELSE 0 END) = 1 THEN 'Professor'
                        ELSE 'Responsavel'
                    END AS funcao
                FROM usuario_funcao uf
                JOIN funcoes f ON f.id = uf.funcao_id
                GROUP BY uf.usuario_id
            ) roles ON roles.usuario_id = mu.usuario_id
            LEFT JOIN max_limites ml ON ml.funcao = COALESCE(roles.funcao, 'Responsavel')
            WHERE mu.mes_ano = ?
            ORDER BY mu.total_mensagens DESC
            LIMIT 10
        ", [$mesAtual]);

        $dados = [];
        $totalMensagensGeral = 0;
        $totalCustoGeral = 0.0;

        foreach ($linhas as $row) {
            $limite = (int) ($row->limite_mensal ?? 20);
            $totalMsgs = (int) $row->total_mensagens;
            $custo = (float) $row->custo_estimado;

            $totalMensagensGeral += $totalMsgs;
            $totalCustoGeral += $custo;

            $dados[] = [
                'nome'            => $row->nome,
                'funcao'          => $row->funcao,
                'total_mensagens' => $totalMsgs,
                'limite'          => $limite,
                'percentual'      => $limite > 0 ? round(($totalMsgs / $limite) * 100) : 0,
                'custo_estimado'  => round($custo, 5),
            ];
        }

        return response()->json([
            'success' => true,
            'mes_ano' => $mesAtual,
            'data'    => $dados,
            'totais'  => [
                'total_mensagens' => $totalMensagensGeral,
                'custo_total_usd' => round($totalCustoGeral, 5),
            ],
        ]);
    }

    private function funcaoPrincipal(array $funcoes): string
    {
        if (in_array('Administrador', $funcoes, true) || in_array('Diretoria', $funcoes, true)) {
            return 'Administrador';
        }
        if (in_array('Professor', $funcoes, true)) {
            return 'Professor';
        }

        return 'Responsavel';
    }
}
