<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documentos\AcessoAlunoService;
use App\Services\Documentos\EscolaConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/documentos/*.php. Cada endpoint só devolve os dados —
 * a montagem visual do PDF continua no frontend (jsPDF/html2canvas), igual
 * ao comportamento atual.
 */
class DocumentoController extends Controller
{
    public function __construct(
        private AcessoAlunoService $acesso,
        private EscolaConfigService $escolaConfig,
    ) {
    }

    public function declaracaoMatricula(Request $request): JsonResponse
    {
        $alunoId = (int) $request->query('aluno_id', 0);
        if (!$alunoId) {
            return response()->json(['error' => 'aluno_id é obrigatório.'], 400);
        }

        if (!$this->acesso->alunoAcessivel($request->attributes->get('max_user'), $alunoId)) {
            return response()->json(['error' => 'Acesso negado a este aluno.'], 403);
        }

        $aluno = DB::selectOne('
            SELECT
                a.id AS aluno_id,
                u.nome_completo AS nome_aluno,
                u.ativo,
                a.matricula,
                a.data_nascimento,
                a.status_matricula,
                t.nome_turma,
                t.turno,
                t.ano_letivo
            FROM alunos a
            JOIN usuarios u ON a.usuario_id = u.id
            LEFT JOIN turma_aluno ta ON ta.aluno_id = a.id
            LEFT JOIN turmas t ON ta.turma_id = t.id
            WHERE a.id = ?
            LIMIT 1
        ', [$alunoId]);

        if (!$aluno) {
            return response()->json(['error' => 'Aluno não encontrado.'], 404);
        }

        // Declaração de Matrícula comprova vínculo ATUAL — diferente do Comprovante
        // de Pagamentos e do Histórico Escolar, que continuam disponíveis mesmo
        // após a saída do aluno.
        if (!$aluno->ativo) {
            return response()->json([
                'error' => 'Este aluno não está mais matriculado na escola. A Declaração de Matrícula não pode ser emitida — utilize o Histórico Escolar, se necessário.',
            ], 422);
        }

        $alunoArr = (array) $aluno;
        $alunoArr['ativo'] = (bool) $aluno->ativo;

        return response()->json([
            'success'      => true,
            'aluno'        => $alunoArr,
            'escola'       => $this->escolaConfig->obter(),
            'data_emissao' => now()->format('Y-m-d'),
        ]);
    }

    public function comprovantePagamentos(Request $request): JsonResponse
    {
        $alunoId = (int) $request->query('aluno_id', 0);
        $ano = (int) $request->query('ano', now()->year);

        if (!$alunoId) {
            return response()->json(['error' => 'aluno_id é obrigatório.'], 400);
        }

        if (!$this->acesso->alunoAcessivel($request->attributes->get('max_user'), $alunoId)) {
            return response()->json(['error' => 'Acesso negado a este aluno.'], 403);
        }

        $aluno = DB::selectOne('
            SELECT a.id AS aluno_id, u.nome_completo AS nome_aluno
            FROM alunos a JOIN usuarios u ON a.usuario_id = u.id
            WHERE a.id = ?
        ', [$alunoId]);

        if (!$aluno) {
            return response()->json(['error' => 'Aluno não encontrado.'], 404);
        }

        // Responsável financeiro: prioriza o marcado como principal; senão, o primeiro vinculado.
        $responsavel = DB::selectOne('
            SELECT u.nome_completo AS nome_responsavel, r.cpf
            FROM aluno_responsavel ar
            JOIN responsaveis r ON ar.responsavel_id = r.id
            JOIN usuarios u ON r.usuario_id = u.id
            WHERE ar.aluno_id = ?
            ORDER BY ar.principal DESC
            LIMIT 1
        ', [$alunoId]);

        // Recibos do ano (valor efetivamente pago, não o valor original da parcela).
        $recibos = DB::select('
            SELECT numero_recibo, data_recibo, valor_recibo, forma_pagamento, descricao
            FROM recibos
            WHERE aluno_id = ? AND YEAR(data_recibo) = ?
            ORDER BY data_recibo ASC
        ', [$alunoId, $ano]);

        $total = 0.0;
        $pagamentos = array_map(function ($row) use (&$total) {
            $row->valor_recibo = (float) $row->valor_recibo;
            $total += $row->valor_recibo;

            return $row;
        }, $recibos);

        return response()->json([
            'success'      => true,
            'aluno'        => $aluno,
            'responsavel'  => $responsavel,
            'ano'          => $ano,
            'pagamentos'   => $pagamentos,
            'total_pago'   => round($total, 2),
            'escola'       => $this->escolaConfig->obter(),
            'data_emissao' => now()->format('Y-m-d'),
        ]);
    }

    public function historicoEscolar(Request $request): JsonResponse
    {
        $alunoId = (int) $request->query('aluno_id', 0);
        if (!$alunoId) {
            return response()->json(['error' => 'aluno_id é obrigatório.'], 400);
        }

        if (!$this->acesso->alunoAcessivel($request->attributes->get('max_user'), $alunoId)) {
            return response()->json(['error' => 'Acesso negado a este aluno.'], 403);
        }

        $aluno = DB::selectOne('
            SELECT a.id AS aluno_id, u.nome_completo AS nome_aluno, a.matricula, a.data_nascimento
            FROM alunos a JOIN usuarios u ON a.usuario_id = u.id
            WHERE a.id = ?
        ', [$alunoId]);

        if (!$aluno) {
            return response()->json(['error' => 'Aluno não encontrado.'], 404);
        }

        // Cada turma/ano letivo distinto que o aluno já cursou, do mais antigo pro mais recente.
        $anos = DB::select('
            SELECT DISTINCT m.ano_letivo, m.turma_id, t.nome_turma
            FROM matriculas m
            JOIN turmas t ON m.turma_id = t.id
            WHERE m.aluno_id = ?
            ORDER BY m.ano_letivo ASC
        ', [$alunoId]);

        $historico = [];
        foreach ($anos as $ano) {
            $notas = DB::selectOne('
                SELECT SUM(n.nota_valor) AS soma_nota, SUM(a.valor_maximo) AS soma_maxima
                FROM notas n
                JOIN avaliacoes a ON n.avaliacao_id = a.id
                WHERE n.aluno_id = ? AND a.turma_id = ?
            ', [$alunoId, $ano->turma_id]);

            $somaNota = (float) ($notas->soma_nota ?? 0);
            $somaMaxima = (float) ($notas->soma_maxima ?? 0);
            $mediaGeral = $somaMaxima > 0 ? round(($somaNota / $somaMaxima) * 10, 1) : null;

            $historico[] = [
                'ano_letivo'  => (int) $ano->ano_letivo,
                'nome_turma'  => $ano->nome_turma,
                'media_geral' => $mediaGeral,
                'status'      => $mediaGeral === null ? 'Sem notas lançadas' : ($mediaGeral >= 6 ? 'Aprovado' : 'Reprovado'),
            ];
        }

        return response()->json([
            'success'      => true,
            'aluno'        => $aluno,
            'historico'    => $historico,
            'escola'       => $this->escolaConfig->obter(),
            'data_emissao' => now()->format('Y-m-d'),
        ]);
    }

    public function fichaAluno(Request $request): JsonResponse
    {
        $alunoId = (int) $request->query('aluno_id', 0);
        $anoLetivo = (int) $request->query('ano_letivo', 0);

        if (!$alunoId || !$anoLetivo) {
            return response()->json(['error' => 'aluno_id e ano_letivo são obrigatórios.'], 400);
        }

        if (!$this->acesso->alunoAcessivel($request->attributes->get('max_user'), $alunoId)) {
            return response()->json(['error' => 'Acesso negado a este aluno.'], 403);
        }

        $aluno = DB::selectOne('
            SELECT a.id AS aluno_id, u.nome_completo AS nome_aluno, a.matricula
            FROM alunos a JOIN usuarios u ON a.usuario_id = u.id
            WHERE a.id = ?
        ', [$alunoId]);

        if (!$aluno) {
            return response()->json(['error' => 'Aluno não encontrado.'], 404);
        }

        // Resolve a turma do aluno naquele ano letivo via matriculas (histórico) —
        // não assume a turma ATUAL, pra continuar certo após promoção/transferência.
        $rowTurma = DB::selectOne("
            SELECT turma_id, t.nome_turma
            FROM matriculas m
            JOIN turmas t ON m.turma_id = t.id
            WHERE m.aluno_id = ? AND m.ano_letivo = ?
            ORDER BY (m.status = 'ativa') DESC, m.id DESC
            LIMIT 1
        ", [$alunoId, $anoLetivo]);

        if (!$rowTurma) {
            return response()->json(['error' => "Este aluno não teve matrícula em $anoLetivo."], 404);
        }

        $turmaId = (int) $rowTurma->turma_id;
        $alunoArr = (array) $aluno;
        $alunoArr['nome_turma'] = $rowTurma->nome_turma;

        $unidades = DB::select(
            'SELECT id, nome_unidade FROM unidades_letivas WHERE ano_letivo = ? ORDER BY id ASC',
            [$anoLetivo]
        );

        $bimestres = [];
        foreach ($unidades as $unidade) {
            $unidadeId = (int) $unidade->id;

            $rows = DB::select('
                SELECT
                    d.id AS disciplina_id,
                    d.nome_disciplina,
                    av.id AS avaliacao_id,
                    ta.nome_tipo AS tipo_avaliacao,
                    ta.peso AS peso_avaliacao,
                    av.valor_maximo,
                    n.id AS nota_registro_id,
                    IFNULL(n.nota_valor, 0.0) AS nota_valor
                FROM turma_professor_disciplina tpd
                JOIN disciplinas d ON tpd.disciplina_id = d.id
                JOIN avaliacoes av ON av.disciplina_id = d.id AND av.unidade_letiva_id = ? AND av.turma_id = tpd.turma_id
                JOIN tipos_avaliacao ta ON av.tipo_avaliacao_id = ta.id
                LEFT JOIN notas n ON n.aluno_id = ? AND n.avaliacao_id = av.id
                WHERE tpd.turma_id = ?
                ORDER BY d.nome_disciplina, ta.peso DESC, av.id
            ', [$unidadeId, $alunoId, $turmaId]);

            $disciplinasMap = [];
            foreach ($rows as $row) {
                $discId = $row->disciplina_id;
                if (!isset($disciplinasMap[$discId])) {
                    $disciplinasMap[$discId] = [
                        'disciplina_id'   => $discId,
                        'nome_disciplina' => $row->nome_disciplina,
                        'avaliacoes'      => [],
                    ];
                }
                $temNota = $row->nota_registro_id !== null;
                $disciplinasMap[$discId]['avaliacoes'][] = [
                    'tipo_avaliacao' => $row->tipo_avaliacao,
                    'peso'           => (int) $row->peso_avaliacao,
                    'valor_maximo'   => (float) $row->valor_maximo,
                    'nota_valor'     => $temNota ? (float) $row->nota_valor : null,
                ];
            }

            // Fórmula igual a get_boletim_aluno.php: SUM(nota/valor_maximo*peso) / 10.
            foreach ($disciplinasMap as $discId => $disc) {
                $somaPonderada = 0.0;
                $somaPesos = 0.0;
                foreach ($disc['avaliacoes'] as $av) {
                    if ($av['nota_valor'] !== null && $av['valor_maximo'] > 0) {
                        $somaPonderada += ($av['nota_valor'] / $av['valor_maximo']) * $av['peso'];
                        $somaPesos += $av['peso'];
                    }
                }
                $disciplinasMap[$discId]['media_bimestral'] = $somaPesos > 0 ? round($somaPonderada / 10, 2) : null;
            }

            $bimestres[] = [
                'unidade'     => ['id' => $unidadeId, 'nome_unidade' => $unidade->nome_unidade],
                'disciplinas' => array_values($disciplinasMap),
            ];
        }

        // Frequência do ano — a tabela `frequencia` não guarda turma_id (só
        // aluno_id + data), então não dá pra filtrar pela turma resolvida acima.
        $freqRow = DB::selectOne('
            SELECT
                COUNT(f.id) AS total_dias,
                SUM(CASE WHEN f.presente = 1 THEN 1 ELSE 0 END) AS total_presencas,
                SUM(CASE WHEN f.presente = 0 THEN 1 ELSE 0 END) AS total_faltas
            FROM frequencia f
            WHERE f.aluno_id = ? AND YEAR(f.data) = ?
        ', [$alunoId, $anoLetivo]);

        $totalDias = (int) ($freqRow->total_dias ?? 0);
        $presencas = (int) ($freqRow->total_presencas ?? 0);
        $faltas = (int) ($freqRow->total_faltas ?? 0);
        $percentual = $totalDias > 0 ? round(($presencas / $totalDias) * 100, 1) : null;

        return response()->json([
            'success'    => true,
            'aluno'      => $alunoArr,
            'ano_letivo' => $anoLetivo,
            'bimestres'  => $bimestres,
            'frequencia' => [
                'total_dias' => $totalDias,
                'presencas'  => $presencas,
                'faltas'     => $faltas,
                'percentual' => $percentual,
            ],
            'escola'       => $this->escolaConfig->obter(),
            'data_emissao' => now()->format('Y-m-d'),
        ]);
    }

    public function fichaInscricao(Request $request): JsonResponse
    {
        $alunoId = (int) $request->query('aluno_id', 0);
        if (!$alunoId) {
            return response()->json(['error' => 'aluno_id é obrigatório.'], 400);
        }

        if (!$this->acesso->alunoAcessivel($request->attributes->get('max_user'), $alunoId)) {
            return response()->json(['error' => 'Acesso negado a este aluno.'], 403);
        }

        $aluno = DB::selectOne('
            SELECT
                a.id AS aluno_id,
                u.nome_completo AS nome_aluno,
                a.matricula,
                a.data_nascimento,
                a.endereco,
                t.nome_turma
            FROM alunos a
            JOIN usuarios u ON a.usuario_id = u.id
            LEFT JOIN turma_aluno ta ON ta.aluno_id = a.id
            LEFT JOIN turmas t ON ta.turma_id = t.id
            WHERE a.id = ?
            LIMIT 1
        ', [$alunoId]);

        if (!$aluno) {
            return response()->json(['error' => 'Aluno não encontrado.'], 404);
        }

        $responsaveis = DB::select('
            SELECT u.nome_completo AS nome_responsavel, r.telefone, u.email AS email_responsavel
            FROM responsaveis r
            JOIN usuarios u ON r.usuario_id = u.id
            JOIN aluno_responsavel ar ON ar.responsavel_id = r.id
            WHERE ar.aluno_id = ?
            ORDER BY u.nome_completo ASC
        ', [$alunoId]);

        return response()->json([
            'success'      => true,
            'aluno'        => $aluno,
            'responsaveis' => $responsaveis,
            'escola'       => $this->escolaConfig->obter(),
            'data_emissao' => now()->format('Y-m-d'),
        ]);
    }
}
