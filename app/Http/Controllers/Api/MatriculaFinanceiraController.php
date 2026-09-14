<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\CancelamentoMatriculaFinanceiraService;
use App\Services\Financeiro\MatriculaFinanceiraService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/financeiro/{post_matricula_financeira,post_matricula_financeira_lote,
 * get_matriculas_financeiras,post_cancelar_matricula_financeira,get_alunos_turma_matricula}.php.
 */
class MatriculaFinanceiraController extends Controller
{
    public function __construct(
        private MatriculaFinanceiraService $matriculas,
        private CancelamentoMatriculaFinanceiraService $cancelamento,
        private LogSistemaService $log,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);
        $data = $request->all();

        try {
            $resultado = $this->matriculas->criar($usuarioIdLogado, $data);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha ao criar matrícula.', 'message' => $e->getMessage()], 400);
        }

        $this->log->registrar(
            $request, $usuarioIdLogado, $jwtUser->nome ?? null, 'CRIACAO_MATRICULA_FINANCEIRA',
            '/api/financeiro/matriculas', 'POST',
            "Matrícula financeira #{$resultado['matricula_id']} criada para aluno #{$data['aluno_id']} ({$resultado['parcelas_geradas']} parcelas geradas)",
            201,
            ['matricula_id' => $resultado['matricula_id'], 'aluno_id' => (int) $data['aluno_id'], 'plano_id' => (int) $data['plano_id'], 'parcelas' => $resultado['parcelas_geradas']]
        );

        return response()->json([
            'success' => true,
            'message' => "Matrícula financeira criada com {$resultado['parcelas_geradas']} parcela(s) gerada(s).",
            'matricula_id' => $resultado['matricula_id'],
            'parcelas_geradas' => $resultado['parcelas_geradas'],
            'valor_parcela' => $resultado['valor_parcela'],
        ], 201);
    }

    /**
     * Matrícula financeira em massa: aplica o mesmo plano/ano letivo/data de início
     * pra vários alunos de uma vez, com desconto e bolsa integral configuráveis
     * individualmente por aluno. Cada aluno é processado isoladamente — se um
     * falhar, os demais do lote continuam sendo processados normalmente.
     */
    public function storeLote(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);

        $planoId = (int) $request->input('plano_id', 0);
        $anoLetivo = trim((string) $request->input('ano_letivo', ''));
        $dataInicio = $request->input('data_inicio');
        $observacoes = trim((string) $request->input('observacoes', ''));
        $alunos = $request->input('alunos', []);

        if (!$planoId || !$anoLetivo || !$dataInicio || !is_array($alunos) || count($alunos) === 0) {
            return response()->json(['error' => 'plano_id, ano_letivo, data_inicio e alunos (array não vazio) são obrigatórios.'], 400);
        }

        $criadas = 0;
        $puladas = 0;
        $erros = 0;
        $resultados = [];

        foreach ($alunos as $item) {
            $alunoId = (int) ($item['aluno_id'] ?? 0);
            if (!$alunoId) {
                continue;
            }

            // Bolsa integral tem prioridade sobre qualquer outro desconto configurado pra esse aluno.
            $bolsaIntegral = !empty($item['bolsa_integral']);
            if ($bolsaIntegral) {
                $descontoPadraoId = null;
                $descontoExtraTipo = 'percentual';
                $descontoExtraValor = 100;
                $descontoExtraDescricao = trim($item['desconto_extra_descricao'] ?? '') ?: 'Bolsa integral';
            } else {
                $descontoPadraoId = isset($item['desconto_padrao_id']) ? (int) $item['desconto_padrao_id'] : null;
                $descontoExtraTipo = $item['desconto_extra_tipo'] ?? null;
                $descontoExtraValor = isset($item['desconto_extra_valor']) ? (float) $item['desconto_extra_valor'] : null;
                $descontoExtraDescricao = trim($item['desconto_extra_descricao'] ?? '');
            }

            if ($this->matriculas->jaMatriculado($alunoId, $anoLetivo)) {
                $puladas++;
                $resultados[] = [
                    'aluno_id' => $alunoId,
                    'status' => 'pulado',
                    'motivo' => 'Já possui matrícula financeira ativa neste ano letivo.',
                ];
                continue;
            }

            try {
                $resultado = $this->matriculas->criar($usuarioIdLogado, [
                    'aluno_id' => $alunoId,
                    'plano_id' => $planoId,
                    'ano_letivo' => $anoLetivo,
                    'data_inicio' => $dataInicio,
                    'desconto_padrao_id' => $descontoPadraoId,
                    'desconto_extra_tipo' => $descontoExtraTipo,
                    'desconto_extra_valor' => $descontoExtraValor,
                    'desconto_extra_descricao' => $descontoExtraDescricao,
                    'observacoes' => $observacoes,
                ]);

                $criadas++;
                $resultados[] = [
                    'aluno_id' => $alunoId,
                    'status' => 'criada',
                    'matricula_id' => $resultado['matricula_id'],
                    'parcelas_geradas' => $resultado['parcelas_geradas'],
                    'valor_parcela' => $resultado['valor_parcela'],
                ];

                $this->log->registrar(
                    $request, $usuarioIdLogado, $jwtUser->nome ?? null, 'CRIACAO_MATRICULA_FINANCEIRA',
                    '/api/financeiro/matriculas/lote', 'POST',
                    "Matrícula financeira #{$resultado['matricula_id']} criada em lote para aluno #$alunoId", 201,
                    ['matricula_id' => $resultado['matricula_id'], 'aluno_id' => $alunoId, 'plano_id' => $planoId]
                );
            } catch (\Throwable $eAluno) {
                $erros++;
                $resultados[] = [
                    'aluno_id' => $alunoId,
                    'status' => 'erro',
                    'motivo' => $eAluno->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'criadas' => $criadas,
            'puladas' => $puladas,
            'erros' => $erros,
            'resultados' => $resultados,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        // Atualiza parcelas pendentes com vencimento passado (mesmo padrão dos outros endpoints).
        DB::statement("UPDATE parcelas SET status = 'vencido' WHERE status = 'pendente' AND data_vencimento < CURDATE()");

        $alunoId = $request->query('aluno_id');
        $turmaId = $request->query('turma_id');
        $planoId = $request->query('plano_id');
        $anoLetivo = $request->query('ano_letivo');
        $status = $request->query('status');

        $query = DB::table('matriculas_financeiras as mf')
            ->join('planos_pagamento as pp', 'mf.plano_id', '=', 'pp.id')
            ->join('alunos as a', 'mf.aluno_id', '=', 'a.id')
            ->join('usuarios as u', 'a.usuario_id', '=', 'u.id')
            ->select([
                'mf.id', 'mf.aluno_id', 'u.nome_completo as aluno_nome',
                DB::raw('(SELECT t.nome_turma FROM turma_aluno ta JOIN turmas t ON ta.turma_id = t.id WHERE ta.aluno_id = mf.aluno_id ORDER BY t.id DESC LIMIT 1) AS turma_nome'),
                'mf.plano_id', 'pp.nome as plano_nome', 'mf.ano_letivo', 'mf.data_inicio', 'mf.status',
                'mf.desconto_padrao_id', 'mf.desconto_extra_tipo', 'mf.desconto_extra_valor', 'mf.observacoes', 'mf.criado_em',
                DB::raw('(SELECT COUNT(*) FROM parcelas p WHERE p.matricula_financeira_id = mf.id) AS total_parcelas'),
                DB::raw("(SELECT COUNT(*) FROM parcelas p WHERE p.matricula_financeira_id = mf.id AND p.status = 'pago') AS parcelas_pagas"),
                DB::raw("(SELECT COUNT(*) FROM parcelas p WHERE p.matricula_financeira_id = mf.id AND p.status = 'vencido') AS parcelas_vencidas"),
                DB::raw("(SELECT COUNT(*) FROM parcelas p WHERE p.matricula_financeira_id = mf.id AND p.status = 'cancelado') AS parcelas_canceladas"),
                DB::raw('(SELECT COALESCE(SUM(p.valor_final), 0) FROM parcelas p WHERE p.matricula_financeira_id = mf.id) AS valor_total'),
                DB::raw("(SELECT COALESCE(SUM(p.valor_pago), 0) FROM parcelas p WHERE p.matricula_financeira_id = mf.id AND p.status = 'pago') AS valor_pago"),
                DB::raw('EXISTS (SELECT 1 FROM baixas_pagamento b JOIN parcelas p2 ON b.parcela_id = p2.id WHERE p2.matricula_financeira_id = mf.id) AS tem_pagamento'),
            ])
            ->orderBy('mf.ano_letivo', 'desc')
            ->orderBy('u.nome_completo');

        if ($alunoId) {
            $query->where('mf.aluno_id', (int) $alunoId);
        }
        if ($turmaId) {
            $turmaIdInt = (int) $turmaId;
            $query->whereExists(function ($sub) use ($turmaIdInt) {
                $sub->select(DB::raw(1))->from('turma_aluno as ta')
                    ->whereColumn('ta.aluno_id', 'mf.aluno_id')
                    ->where('ta.turma_id', $turmaIdInt);
            });
        }
        if ($planoId) {
            $query->where('mf.plano_id', (int) $planoId);
        }
        if ($anoLetivo) {
            $query->where('mf.ano_letivo', $anoLetivo);
        }
        if ($status && in_array($status, ['ativa', 'cancelada', 'concluida'], true)) {
            $query->where('mf.status', $status);
        }

        $matriculas = $query->get()->map(function ($row) {
            $arr = (array) $row;
            $arr['desconto_extra_valor'] = $row->desconto_extra_valor !== null ? (float) $row->desconto_extra_valor : null;
            $arr['total_parcelas'] = (int) $row->total_parcelas;
            $arr['parcelas_pagas'] = (int) $row->parcelas_pagas;
            $arr['parcelas_vencidas'] = (int) $row->parcelas_vencidas;
            $arr['parcelas_canceladas'] = (int) $row->parcelas_canceladas;
            $arr['valor_total'] = (float) $row->valor_total;
            $arr['valor_pago'] = (float) $row->valor_pago;
            $arr['tem_pagamento'] = (bool) $row->tem_pagamento;

            return $arr;
        })->values();

        return response()->json(['success' => true, 'count' => $matriculas->count(), 'data' => $matriculas]);
    }

    public function cancelar(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);

        $motivosValidos = ['lancamento_errado', 'aluno_transferido', 'aluno_evadido', 'outro'];

        $matriculaId = (int) $request->input('matricula_id', 0);
        $motivo = $request->input('motivo');
        $observacoes = $request->filled('observacoes') ? trim($request->input('observacoes')) : null;
        $perdoarVencidas = (bool) $request->input('perdoar_vencidas', false);

        if (!$matriculaId) {
            return response()->json(['error' => 'matricula_id é obrigatório.'], 400);
        }
        if (!$motivo || !in_array($motivo, $motivosValidos, true)) {
            return response()->json(['error' => 'motivo é obrigatório. Valores aceitos: ' . implode(', ', $motivosValidos)], 400);
        }

        try {
            $resultado = $this->cancelamento->cancelar($matriculaId, $motivo, $observacoes, $perdoarVencidas, $usuarioIdLogado);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Erro ao cancelar matrícula.', 'message' => $e->getMessage()], 400);
        }

        $this->log->registrar(
            $request, $usuarioIdLogado, $jwtUser->nome ?? null, 'CANCELAMENTO_MATRICULA_FINANCEIRA',
            '/api/financeiro/matriculas/cancelar', 'POST',
            "Matrícula financeira #$matriculaId {$resultado['modo']} — motivo: $motivo", 200,
            [
                'matricula_id' => $matriculaId, 'motivo' => $motivo, 'observacoes' => $observacoes,
                'perdoar_vencidas' => $perdoarVencidas, 'modo' => $resultado['modo'], 'parcelas_afetadas' => $resultado['parcelas_afetadas'],
            ]
        );

        return response()->json([
            'success' => true,
            'modo' => $resultado['modo'],
            'parcelas_afetadas' => $resultado['parcelas_afetadas'],
            'message' => $resultado['modo'] === 'excluida'
                ? 'Matrícula financeira excluída (nenhum pagamento registrado).'
                : 'Matrícula financeira cancelada.',
        ]);
    }

    /**
     * Lista os alunos de uma turma junto com o status de matrícula financeira no
     * ano letivo informado — base pra tela de "Matrícula em Massa" saber quem já
     * está matriculado (pré-selecionado como pulado) e quem está pendente.
     */
    public function alunosTurmaMatricula(Request $request): JsonResponse
    {
        $turmaId = (int) $request->query('turma_id', 0);
        $anoLetivo = trim((string) $request->query('ano_letivo', ''));

        if (!$turmaId || !$anoLetivo) {
            return response()->json(['error' => 'turma_id e ano_letivo são obrigatórios.'], 400);
        }

        $rows = DB::select('
            SELECT
                al.id AS aluno_id,
                u.nome_completo AS nome_aluno,
                mf.id AS matricula_id,
                p.nome AS plano_nome
            FROM turma_aluno ta
            JOIN alunos al ON ta.aluno_id = al.id
            JOIN usuarios u ON al.usuario_id = u.id
            LEFT JOIN matriculas_financeiras mf
                ON mf.aluno_id = al.id AND mf.ano_letivo = ? AND mf.status = ?
            LEFT JOIN planos_pagamento p ON mf.plano_id = p.id
            WHERE ta.turma_id = ?
            ORDER BY u.nome_completo ASC
        ', [$anoLetivo, 'ativa', $turmaId]);

        $alunos = array_map(fn ($row) => [
            'aluno_id' => (int) $row->aluno_id,
            'nome_aluno' => $row->nome_aluno,
            'ja_matriculado' => $row->matricula_id !== null,
            'matricula_id' => $row->matricula_id !== null ? (int) $row->matricula_id : null,
            'plano_nome' => $row->plano_nome,
        ], $rows);

        return response()->json(['success' => true, 'count' => count($alunos), 'data' => $alunos]);
    }
}
