<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\ContratoFinanceiroService;
use App\Services\Financeiro\FinanceiroConfigService;
use App\Services\Financeiro\Sicoob\RemessaSicoobService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pendências da Visão Geral, alunos sem contrato, sugestão de plano e
 * dispensas — a ponte entre o cadastro/virada e o financeiro.
 */
class ContratoFinanceiroController extends Controller
{
    public function __construct(
        private ContratoFinanceiroService $contratos,
        private FinanceiroConfigService $config,
        private RemessaSicoobService $remessa,
        private LogSistemaService $log,
    ) {
    }

    /** O que precisa de ação agora — base dos cards da Visão Geral. */
    public function pendencias(): JsonResponse
    {
        DB::statement("UPDATE parcelas SET status = 'vencido' WHERE status = 'pendente' AND data_vencimento < CURDATE()");
        $config = $this->config->todas();

        $semContrato = array_map(fn ($ano) => ['ano' => $ano, 'total' => $this->contratos->contarSemContrato($ano)],
            $this->contratos->anosComTurmasAtivas());

        $vencidas = DB::table('parcelas')->where('status', 'vencido')
            ->selectRaw('COUNT(*) AS qtd, COALESCE(SUM(valor_final), 0) AS valor, COUNT(DISTINCT aluno_id) AS alunos')->first();

        $sicoob = null;
        if ($config['modo_cobranca'] === 'sicoob_arquivo') {
            $candidatas = $this->remessa->candidatas($config['remessa_dias_antecedencia']);
            $ultimo = DB::table('retornos_sicoob')->max('processado_em');
            $emAberto = DB::table('boletos_bancarios')->whereIn('status', ['enviado', 'registrado'])->count();
            $sicoob = [
                'parcelas_prontas'    => count(array_filter($candidatas, fn ($c) => $c['pronta'])),
                'parcelas_com_pendencia' => count(array_filter($candidatas, fn ($c) => !$c['pronta'])),
                'remessas_aguardando' => DB::table('remessas_sicoob')->where('status', 'gerado')->count(),
                'boletos_em_aberto'   => $emAberto,
                'boletos_rejeitados'  => DB::table('boletos_bancarios')->where('status', 'rejeitado')->count(),
                'ultimo_retorno'      => $ultimo,
                'dias_sem_retorno'    => $ultimo ? (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($ultimo)->startOfDay(), true) : null,
            ];
        }

        return response()->json(['success' => true, 'data' => [
            'modo_cobranca' => $config['modo_cobranca'],
            'sem_contrato'  => $semContrato,
            'vencidas'      => ['quantidade' => (int) $vencidas->qtd, 'valor' => (float) $vencidas->valor, 'alunos' => (int) $vencidas->alunos],
            'sicoob'        => $sicoob,
        ]]);
    }

    public function semContrato(Request $request): JsonResponse
    {
        $ano = (int) $request->query('ano', now()->year);
        return response()->json(['success' => true, 'ano' => $ano, 'data' => $this->contratos->semContrato($ano)]);
    }

    public function sugestao(Request $request): JsonResponse
    {
        $alunoId = (int) $request->query('aluno_id', 0);
        $turmaId = $request->query('turma_id') ? (int) $request->query('turma_id') : null;
        $ano = (int) $request->query('ano', 0);
        if (!$ano && $turmaId) {
            $ano = (int) DB::table('turmas')->where('id', $turmaId)->value('ano_letivo');
        }
        $ano = $ano ?: (int) now()->year;

        return response()->json(['success' => true, 'data' => $this->contratos->sugestao($alunoId, $turmaId, $ano)]);
    }

    public function dispensas(Request $request): JsonResponse
    {
        $ano = (int) $request->query('ano', now()->year);
        return response()->json(['success' => true, 'data' => $this->contratos->dispensas($ano)]);
    }

    public function dispensar(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $alunoIds = array_filter(array_map('intval', (array) ($request->input('aluno_ids') ?? [$request->input('aluno_id')])));
        $ano = (int) $request->input('ano_letivo', 0);
        $motivo = trim((string) $request->input('motivo', '')) ?: null;
        if (!$alunoIds || $ano < 2000) {
            return response()->json(['error' => 'Informe o(s) aluno(s) e o ano letivo.'], 422);
        }
        foreach ($alunoIds as $alunoId) {
            $this->contratos->dispensar($alunoId, $ano, $motivo, (int) ($jwtUser->id ?? 0));
        }
        $this->log->registrar($request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'DISPENSA_CONTRATO_FINANCEIRO',
            '/api/financeiro/contratos/dispensas', 'POST', count($alunoIds) . " aluno(s) dispensado(s) de contrato em $ano", 200,
            ['aluno_ids' => $alunoIds, 'ano_letivo' => $ano, 'motivo' => $motivo]);

        return response()->json(['success' => true]);
    }

    public function removerDispensa(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $alunoId = (int) $request->input('aluno_id', 0);
        $ano = (int) $request->input('ano_letivo', 0);
        $this->contratos->removerDispensa($alunoId, $ano);
        $this->log->registrar($request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'DISPENSA_CONTRATO_REMOVIDA',
            '/api/financeiro/contratos/dispensas', 'DELETE', "Dispensa do aluno #$alunoId em $ano removida", 200,
            ['aluno_id' => $alunoId, 'ano_letivo' => $ano]);

        return response()->json(['success' => true]);
    }
}
