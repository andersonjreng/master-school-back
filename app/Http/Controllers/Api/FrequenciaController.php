<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Frequencia\FrequenciaAccessService;
use App\Services\Frequencia\ResumoGeralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/frequencia/*.php, mais dois endpoints agregados novos
 * (resumoGeral, resumoMensal) que não existiam no legado — ver ResumoGeralService.
 */
class FrequenciaController extends Controller
{
    public function __construct(
        private FrequenciaAccessService $access,
        private ResumoGeralService $resumoGeral,
    ) {
    }

    /**
     * Percentual de presença de todas as turmas da escola num ano/mês, numa
     * query só — usado pela Home (Admin) pra evitar 1 chamada por turma. Mesmo
     * dado que a tool `buscar_frequencia_geral` do Max usa por baixo.
     */
    public function resumoGeral(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        if (!$this->access->possuiPapel($jwtUser, ['Administrador', 'Diretoria'])) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $ano = (int) $request->query('ano', now()->year);
        $mes = $request->query('mes') ? (int) $request->query('mes') : null;

        return response()->json($this->resumoGeral->porTurma($ano, $mes));
    }

    /**
     * Percentual de presença médio da escola, por mês, num intervalo — usado
     * pelo gráfico "Frequência Geral da Escola" da Home. Substitui N-meses x
     * N-turmas chamadas individuais por uma única query agregada.
     */
    public function resumoMensal(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        if (!$this->access->possuiPapel($jwtUser, ['Administrador', 'Diretoria'])) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $ano = (int) $request->query('ano', now()->year);
        $mesInicio = (int) $request->query('mes_inicio', 1);
        $mesFim = (int) $request->query('mes_fim', 12);

        return response()->json($this->resumoGeral->porMes($ano, $mesInicio, $mesFim));
    }

    /**
     * Equivalente a get_alunos_por_turma.php — roster de alunos (+ responsáveis)
     * de uma turma. Restrito a Administrador/Professor (sem esse endpoint,
     * Responsável não tem motivo pra listar a turma inteira).
     */
    public function alunosPorTurma(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        if (!$this->access->possuiPapel($jwtUser, ['Administrador', 'Professor'])) {
            return response()->json(['error' => 'Acesso negado. Esta API é restrita.'], 403);
        }

        $turmaId = $request->query('turma_id');
        if (!$turmaId || !is_numeric($turmaId)) {
            return response()->json(['error' => 'ID da turma é obrigatório. Ex: ?turma_id=1'], 400);
        }

        // incluir_historico=1 troca o roster de "quem está vinculado agora" (turma_aluno
        // — usado pela promoção em lote) para "quem já teve matrícula nessa turma"
        // (matriculas — usado por relatórios históricos de boletim/frequência).
        $incluirHistorico = $request->query('incluir_historico') === '1';
        $tabelaRoster = $incluirHistorico
            ? '(SELECT DISTINCT aluno_id, turma_id FROM matriculas) ta'
            : 'turma_aluno ta';

        $rows = DB::select("
            SELECT
                al.id AS aluno_id,
                u_aluno.nome_completo AS nome_aluno,
                al.matricula,
                al.data_nascimento,
                ar.parentesco,
                r.id AS responsavel_id,
                u_resp.nome_completo AS nome_responsavel,
                u_resp.email AS email_responsavel
            FROM {$tabelaRoster}
            JOIN alunos al ON ta.aluno_id = al.id
            JOIN usuarios u_aluno ON al.usuario_id = u_aluno.id
            LEFT JOIN aluno_responsavel ar ON al.id = ar.aluno_id
            LEFT JOIN responsaveis r ON ar.responsavel_id = r.id
            LEFT JOIN usuarios u_resp ON r.usuario_id = u_resp.id
            WHERE ta.turma_id = ?
            ORDER BY u_aluno.nome_completo
        ", [(int) $turmaId]);

        $alunos = [];
        foreach ($rows as $row) {
            $alunoId = $row->aluno_id;
            if (!isset($alunos[$alunoId])) {
                $alunos[$alunoId] = [
                    'id'              => $row->aluno_id,
                    'nome'            => $row->nome_aluno,
                    'matricula'       => $row->matricula,
                    'data_nascimento' => $row->data_nascimento,
                    'responsaveis'    => [],
                ];
            }
            if ($row->responsavel_id !== null) {
                $alunos[$alunoId]['responsaveis'][] = [
                    'responsavel_id' => $row->responsavel_id,
                    'nome'           => $row->nome_responsavel,
                    'email'          => $row->email_responsavel,
                    'parentesco'     => $row->parentesco,
                ];
            }
        }

        return response()->json(array_values($alunos));
    }

    /**
     * Equivalente a get_frequencia_por_aluno.php — total de presenças/faltas
     * agregado por aluno (uma linha por aluno), filtrável por turma/ano/mes.
     */
    public function porAluno(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        if (!$this->access->possuiPapel($jwtUser, ['Administrador', 'Professor', 'Responsável', 'Responsavel'])) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $ano = $request->query('ano');
        $mes = $request->query('mes');
        $turmaId = $request->query('turma_id');
        $alunoId = $request->query('aluno_id');

        if ($this->access->responsavelSemAcessoAmplo($jwtUser)) {
            if (!$alunoId) {
                return response()->json(['error' => 'aluno_id é obrigatório para o perfil Responsável.'], 400);
            }
            if (!$this->access->dependenteValido($jwtUser, (int) $alunoId)) {
                return response()->json(['error' => 'Acesso negado. Este aluno não é seu dependente.'], 403);
            }
        }

        $sql = '
            SELECT
                a.id AS aluno_id,
                u.nome_completo AS nome_aluno,
                ta.turma_id,
                t.nome_turma,
                SUM(CASE WHEN f.presente = 1 THEN 1 ELSE 0 END) as total_presencas,
                SUM(CASE WHEN f.presente = 0 THEN 1 ELSE 0 END) as total_faltas
            FROM alunos a
            INNER JOIN usuarios u ON a.usuario_id = u.id
            INNER JOIN turma_aluno ta ON a.id = ta.aluno_id
            INNER JOIN turmas t ON t.id = ta.turma_id
            LEFT JOIN frequencia f ON a.id = f.aluno_id
        ';

        $dateFilters = [];
        $params = [];

        if ($ano) {
            $dateFilters[] = 'YEAR(f.data) = ?';
            $params[] = $ano;
        }
        if ($mes) {
            $dateFilters[] = 'MONTH(f.data) = ?';
            $params[] = $mes;
        }
        if ($dateFilters) {
            $sql .= ' AND ' . implode(' AND ', $dateFilters);
        }

        $whereConditions = [];
        if ($turmaId) {
            $whereConditions[] = 'ta.turma_id = ?';
            $params[] = $turmaId;
        }
        if ($alunoId) {
            $whereConditions[] = 'a.id = ?';
            $params[] = $alunoId;
        } else {
            // Sem aluno_id = consulta "em lote" por turma (usada pelo widget de "alunos
            // em atenção") — não considera quem já foi inativado. Com aluno_id o
            // admin/responsável já escolheu um aluno específico (drill-down), então
            // não filtramos por status.
            $whereConditions[] = 'u.ativo = 1';
        }
        $sql .= ' WHERE ' . implode(' AND ', $whereConditions);
        $sql .= ' GROUP BY a.id, u.nome_completo, ta.turma_id, t.nome_turma ORDER BY u.nome_completo ASC';

        $rows = DB::select($sql, $params);

        $alunosFrequencia = array_map(fn ($row) => [
            'alunoId'        => (int) $row->aluno_id,
            'nome'           => $row->nome_aluno,
            'turmaId'        => (int) $row->turma_id,
            'nomeTurma'      => $row->nome_turma,
            'totalPresencas' => (int) $row->total_presencas,
            'totalFaltas'    => (int) $row->total_faltas,
        ], $rows);

        return response()->json($alunosFrequencia);
    }

    /**
     * Equivalente a get_frequencia_por_dia.php — total de presenças/faltas
     * agregado por dia (uma linha por data), filtrável por turma/ano/mes/aluno.
     */
    public function porDia(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        if (!$this->access->possuiPapel($jwtUser, ['Administrador', 'Professor', 'Responsável', 'Responsavel'])) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $ano = $request->query('ano');
        $mes = $request->query('mes');
        $turmaId = $request->query('turma_id');
        $alunoId = $request->query('aluno_id');

        if ($this->access->responsavelSemAcessoAmplo($jwtUser)) {
            if (!$alunoId) {
                return response()->json(['error' => 'aluno_id é obrigatório para o perfil Responsável.'], 400);
            }
            if (!$this->access->dependenteValido($jwtUser, (int) $alunoId)) {
                return response()->json(['error' => 'Acesso negado. Este aluno não é seu dependente.'], 403);
            }
        }

        $sql = '
            SELECT
                f.data,
                SUM(CASE WHEN f.presente = 1 THEN 1 ELSE 0 END) as total_presencas,
                SUM(CASE WHEN f.presente = 0 THEN 1 ELSE 0 END) as total_faltas
            FROM frequencia f
            WHERE 1=1
        ';
        $params = [];

        // Filtro de turma via matriculas (histórico), não turma_aluno (estado atual),
        // pra não excluir alunos já transferidos/promovidos com frequência lançada
        // nessa turma naquele período.
        if ($turmaId) {
            $sql .= ' AND EXISTS (SELECT 1 FROM matriculas m WHERE m.aluno_id = f.aluno_id AND m.turma_id = ?) ';
            $params[] = $turmaId;
        }
        if ($ano) {
            $sql .= ' AND YEAR(f.data) = ? ';
            $params[] = $ano;
        }
        if ($mes) {
            $sql .= ' AND MONTH(f.data) = ? ';
            $params[] = $mes;
        }
        if ($alunoId && is_numeric($alunoId)) {
            $sql .= ' AND f.aluno_id = ? ';
            $params[] = $alunoId;
        }
        $sql .= ' GROUP BY f.data ORDER BY f.data ASC';

        $rows = DB::select($sql, $params);

        $relatorio = array_map(fn ($row) => [
            'date'           => $row->data,
            'totalPresencas' => (int) $row->total_presencas,
            'totalFaltas'    => (int) $row->total_faltas,
        ], $rows);

        return response()->json($relatorio);
    }

    /**
     * Equivalente a get_resumo_frequencia.php — percentual de presença resumido
     * de uma turma (+ opcionalmente um aluno específico) num ano/mês.
     */
    public function resumo(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        if (!$this->access->possuiPapel($jwtUser, ['Administrador', 'Diretoria', 'Professor', 'Responsável', 'Responsavel'])) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $ano = $request->query('ano');
        $mes = $request->query('mes');
        $turmaId = $request->query('turma_id');
        $alunoId = $request->query('aluno_id');

        if (!$ano || !$turmaId) {
            return response()->json(['error' => 'Ano e turma_id são obrigatórios.'], 400);
        }

        if ($this->access->responsavelSemAcessoAmplo($jwtUser, ['Administrador', 'Diretoria', 'Professor'])) {
            if (!$alunoId) {
                return response()->json(['error' => 'aluno_id é obrigatório para o perfil Responsável.'], 400);
            }
            if (!$this->access->dependenteValido($jwtUser, (int) $alunoId)) {
                return response()->json(['error' => 'Acesso negado. Este aluno não é seu dependente.'], 403);
            }
        }

        $sql = '
            SELECT
                COUNT(f.id) as total_registros,
                SUM(CASE WHEN f.presente = 1 THEN 1 ELSE 0 END) as total_presencas,
                SUM(CASE WHEN f.presente = 0 THEN 1 ELSE 0 END) as total_faltas,
                COUNT(DISTINCT f.data) as total_dias_aula
            FROM frequencia f
            INNER JOIN (SELECT DISTINCT aluno_id, turma_id FROM matriculas) ta
                ON f.aluno_id = ta.aluno_id AND ta.turma_id = ?
            WHERE YEAR(f.data) = ?
        ';
        $params = [$turmaId, $ano];

        if ($mes) {
            $sql .= ' AND MONTH(f.data) = ?';
            $params[] = $mes;
        }
        if ($alunoId) {
            $sql .= ' AND f.aluno_id = ?';
            $params[] = $alunoId;
        }

        $row = DB::selectOne($sql, $params);

        $totalRegistros = (int) $row->total_registros;
        $totalPresencas = (int) $row->total_presencas;
        $percentual = $totalRegistros > 0 ? ($totalPresencas / $totalRegistros) * 100 : 0;

        return response()->json([
            'percentualPresenca'  => round($percentual, 1),
            'totalPresencas'      => $totalPresencas,
            'totalFaltas'         => (int) $row->total_faltas,
            'totalAulasNoPeriodo' => (int) $row->total_dias_aula,
        ]);
    }
}
