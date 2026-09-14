<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Avaliacoes\ProfessorResolver;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Equivalente a api/professores/*.php. Não portados (dead code, sem uso no
 * frontend): get_frequencias.php e post_frequencia.php (escrevem/leem uma
 * tabela `frequencias` no plural que não existe — a real é `frequencia`,
 * singular, usada por salvar_frequencia.php e pelo módulo frequencia/ já
 * migrado); get_todos_alunos.php (não referenciado em lugar nenhum do
 * frontend, superado por alunos/get_alunos_detalhes.php).
 */
class ProfessorController extends Controller
{
    public function __construct(
        private ProfessorResolver $professores,
        private LogSistemaService $log,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas administradores podem listar todos os professores.'], 403);
        }

        $rows = DB::select("
            SELECT
                p.id AS professor_id, u.id AS usuario_id, u.nome_completo, u.email, u.ativo,
                u.data_cadastro, u.ultimo_login, p.data_contratacao,
                GROUP_CONCAT(DISTINCT CONCAT(t.nome_turma, ' (', d.nome_disciplina, ')') SEPARATOR '; ') AS turmas_vinculadas
            FROM professores p
            JOIN usuarios u ON p.usuario_id = u.id
            LEFT JOIN turma_professor_disciplina tpd ON p.id = tpd.professor_id
            LEFT JOIN turmas t ON tpd.turma_id = t.id
            LEFT JOIN disciplinas d ON tpd.disciplina_id = d.id
            GROUP BY p.id, u.id, u.nome_completo, u.email, u.ativo, u.data_cadastro, u.ultimo_login, p.data_contratacao
            ORDER BY u.nome_completo ASC
        ");

        $professoresList = array_map(function ($row) {
            $arr = (array) $row;
            $arr['ativo_status'] = $row->ativo == 1 ? 'Ativo' : 'Inativo';
            $arr['ativo'] = (bool) $row->ativo;
            $arr['turmas_vinculadas_array'] = $row->turmas_vinculadas ? explode('; ', $row->turmas_vinculadas) : [];
            unset($arr['turmas_vinculadas']);

            return $arr;
        }, $rows);

        return response()->json(['success' => true, 'count' => count($professoresList), 'data' => $professoresList]);
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas administradores podem cadastrar professores.'], 403);
        }

        $nomeCompleto = $request->input('nome_completo');
        $email = $request->input('email');
        $senhaBruta = $request->input('senha');
        $registroFuncional = $request->input('registro_funcional');
        $dataContratacao = $request->input('data_contratacao', now()->format('Y-m-d'));

        if (!$nomeCompleto || !$email || !$senhaBruta || !$registroFuncional) {
            return response()->json(['error' => 'Os campos obrigatórios (nome_completo, email, senha, registro_funcional) devem ser fornecidos.'], 400);
        }

        try {
            $usuarioId = DB::transaction(function () use ($nomeCompleto, $email, $senhaBruta, $registroFuncional, $dataContratacao) {
                $usuarioId = DB::table('usuarios')->insertGetId([
                    'nome_completo' => $nomeCompleto,
                    'email'         => $email,
                    'senha'         => Hash::make($senhaBruta),
                    'data_cadastro' => now(),
                    'ativo'         => 1,
                ]);

                DB::table('professores')->insert([
                    'usuario_id'          => $usuarioId,
                    'registro_funcional'  => $registroFuncional,
                    'data_contratacao'    => $dataContratacao,
                ]);

                // ID 3 = Professor (ver tabela funcoes).
                DB::table('usuario_funcao')->insert(['usuario_id' => $usuarioId, 'funcao_id' => 3]);

                return $usuarioId;
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha no cadastro transacional.', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success'            => 'Professor cadastrado com sucesso.',
            'usuario_id'         => $usuarioId,
            'registro_funcional' => $registroFuncional,
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $usuarioId = $request->input('usuario_id');
        if (!$usuarioId) {
            return response()->json(['error' => 'O campo usuario_id é obrigatório para edição.'], 400);
        }

        try {
            DB::transaction(function () use ($request, $usuarioId) {
                $camposUsuario = [];
                if ($request->has('nome_completo')) {
                    $camposUsuario['nome_completo'] = $request->input('nome_completo');
                }
                if ($request->has('email')) {
                    $camposUsuario['email'] = $request->input('email');
                }
                if ($request->filled('senha')) {
                    $camposUsuario['senha'] = Hash::make($request->input('senha'));
                }
                if ($request->has('ativo')) {
                    $camposUsuario['ativo'] = $request->input('ativo');
                }
                if ($camposUsuario) {
                    DB::table('usuarios')->where('id', $usuarioId)->update($camposUsuario);
                }

                $camposProf = [];
                if ($request->has('registro_funcional')) {
                    $camposProf['registro_funcional'] = $request->input('registro_funcional');
                }
                if ($request->has('data_contratacao')) {
                    $camposProf['data_contratacao'] = $request->input('data_contratacao');
                }
                if ($camposProf) {
                    DB::table('professores')->where('usuario_id', $usuarioId)->update($camposProf);
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Erro ao atualizar.', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => 'Professor atualizado com sucesso.']);
    }

    public function minhasTurmas(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Professor', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Esta API é exclusiva para Professores.'], 403);
        }

        $professorId = $this->professores->idDoUsuario((int) ($jwtUser->id ?? 0));
        if (!$professorId) {
            return response()->json(['error' => 'Professor não encontrado na tabela de professores.'], 404);
        }

        $rows = DB::select('
            SELECT t.id AS turma_id, t.nome_turma, t.ano_letivo, t.turno, t.status, d.id AS disciplina_id, d.nome_disciplina
            FROM turma_professor_disciplina tpd
            JOIN turmas t ON tpd.turma_id = t.id
            JOIN disciplinas d ON tpd.disciplina_id = d.id
            WHERE tpd.professor_id = ?
            ORDER BY t.ano_letivo DESC, t.nome_turma, d.nome_disciplina
        ', [$professorId]);

        $turmas = [];
        foreach ($rows as $row) {
            $turmaId = $row->turma_id;
            $turmas[$turmaId] ??= [
                'id'                     => $row->turma_id,
                'nome_turma'             => $row->nome_turma,
                'ano_letivo'             => $row->ano_letivo,
                'turno'                  => $row->turno,
                'status'                 => $row->status,
                'disciplinas_lecionadas' => [],
            ];
            $turmas[$turmaId]['disciplinas_lecionadas'][] = [
                'disciplina_id'   => $row->disciplina_id,
                'nome_disciplina' => $row->nome_disciplina,
            ];
        }

        return response()->json(array_values($turmas));
    }

    public function minhasDisciplinas(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $isProfessor = in_array('Professor', $funcoes, true);
        $isAdminOuDiretor = in_array('Administrador', $funcoes, true) || in_array('Diretor', $funcoes, true);

        if (!$isProfessor && !$isAdminOuDiretor) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $turmaId = $request->query('turma_id');
        $turmaIdValido = $turmaId !== null && is_numeric($turmaId);

        if ($isProfessor) {
            $professorId = $this->professores->idDoUsuario((int) ($jwtUser->id ?? 0));
            if (!$professorId) {
                return response()->json(['error' => 'Professor não encontrado.', 'message' => 'Usuário com função Professor não encontrado na tabela professores.'], 404);
            }

            $sql = '
                SELECT DISTINCT d.id AS disciplina_id, d.nome_disciplina AS nome_disciplina
                FROM disciplinas d
                JOIN turma_professor_disciplina tpd ON d.id = tpd.disciplina_id
                WHERE tpd.professor_id = ?
            ';
            $params = [$professorId];
            if ($turmaIdValido) {
                $sql .= ' AND tpd.turma_id = ?';
                $params[] = $turmaId;
            }
        } else {
            $sql = 'SELECT d.id AS disciplina_id, d.nome_disciplina AS nome_disciplina FROM disciplinas d';
            $params = [];
            if ($turmaIdValido) {
                $sql .= ' JOIN turma_professor_disciplina tpd ON d.id = tpd.disciplina_id WHERE tpd.turma_id = ?';
                $params[] = $turmaId;
            }
        }
        $sql .= ' ORDER BY d.nome_disciplina ASC';

        $rows = DB::select($sql, $params);
        $disciplinas = array_map(fn ($row) => ['id' => (int) $row->disciplina_id, 'nome' => $row->nome_disciplina], $rows);

        return response()->json(['success' => true, 'disciplinas' => $disciplinas]);
    }

    /**
     * Roster de alunos (+ responsáveis) de uma turma, com campo `ativo` e
     * opção de histórico — variante própria de professores/, diferente (e
     * levemente distinta) de FrequenciaController::alunosPorTurma().
     */
    public function alunosPorTurma(Request $request): JsonResponse
    {
        $turmaId = $request->query('turma_id');
        if (!$turmaId || !is_numeric($turmaId)) {
            return response()->json(['error' => 'ID da turma é obrigatório. Ex: ?turma_id=1'], 400);
        }

        $incluirHistorico = $request->query('incluir_historico') === '1';
        $tabelaRoster = $incluirHistorico
            ? '(SELECT DISTINCT aluno_id, turma_id FROM matriculas) ta'
            : 'turma_aluno ta';
        $filtroAtivo = $incluirHistorico ? '' : 'AND u_aluno.ativo = 1';

        $rows = DB::select("
            SELECT
                al.id AS aluno_id, u_aluno.nome_completo AS nome_aluno, u_aluno.ativo,
                al.matricula, al.data_nascimento, ar.parentesco,
                r.id AS responsavel_id, u_resp.nome_completo AS nome_responsavel, u_resp.email AS email_responsavel
            FROM {$tabelaRoster}
            JOIN alunos al ON ta.aluno_id = al.id
            JOIN usuarios u_aluno ON al.usuario_id = u_aluno.id
            LEFT JOIN aluno_responsavel ar ON al.id = ar.aluno_id
            LEFT JOIN responsaveis r ON ar.responsavel_id = r.id
            LEFT JOIN usuarios u_resp ON r.usuario_id = u_resp.id
            WHERE ta.turma_id = ? {$filtroAtivo}
            ORDER BY u_aluno.nome_completo
        ", [$turmaId]);

        $alunos = [];
        foreach ($rows as $row) {
            $alunoId = $row->aluno_id;
            $alunos[$alunoId] ??= [
                'id'               => $row->aluno_id,
                'nome'             => $row->nome_aluno,
                'ativo'            => (bool) $row->ativo,
                'matricula'        => $row->matricula,
                'data_nascimento'  => $row->data_nascimento,
                'responsaveis'     => [],
            ];
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
     * Chamada do dia de uma turma: quem já foi marcado presente/faltante,
     * com estatísticas do dia (equivalente a get_frequencia_por_turma.php).
     */
    public function frequenciaPorTurma(Request $request): JsonResponse
    {
        $turmaId = $request->query('turma_id');
        $dataConsulta = $request->query('data');

        if (!$turmaId || !$dataConsulta) {
            return response()->json(['error' => 'Parâmetros turma_id e data são obrigatórios.'], 400);
        }

        $rows = DB::select('
            SELECT a.id AS aluno_id, a.matricula, u.nome_completo AS nome_aluno, f.presente, f.justificativa, f.data AS data_registro
            FROM turma_aluno ta
            JOIN alunos a ON ta.aluno_id = a.id
            JOIN usuarios u ON a.usuario_id = u.id
            LEFT JOIN frequencia f ON a.id = f.aluno_id AND f.data = ?
            WHERE ta.turma_id = ? AND u.ativo = 1
            ORDER BY u.nome_completo ASC
        ', [$dataConsulta, $turmaId]);

        $totalAlunos = 0;
        $totalPresentes = 0;
        $chamadaRealizada = false;
        $lista = [];

        foreach ($rows as $row) {
            $totalAlunos++;
            $item = (array) $row;

            if ($row->presente === null) {
                $item['status_chamada'] = 'Não realizada';
                $item['presente'] = null;
            } else {
                $chamadaRealizada = true;
                $item['status_chamada'] = 'Registrada';
                $item['presente'] = (bool) $row->presente;
                if ($row->presente) {
                    $totalPresentes++;
                }
            }
            $lista[] = $item;
        }

        $percentual = ($totalAlunos > 0 && $chamadaRealizada) ? ($totalPresentes / $totalAlunos) * 100 : 0;

        return response()->json([
            'success'      => true,
            'turma_id'     => $turmaId,
            'data'         => $dataConsulta,
            'estatisticas' => [
                'total_alunos'        => $totalAlunos,
                'total_presentes'     => $totalPresentes,
                'total_faltas'        => $chamadaRealizada ? ($totalAlunos - $totalPresentes) : 0,
                'percentual_presenca' => round($percentual, 2) . '%',
                'status_dia'          => $chamadaRealizada ? 'Finalizado' : 'Pendente',
            ],
            'data_list' => $lista,
        ]);
    }

    /**
     * Resumo anual de frequência de um aluno (equivalente a
     * get_historico_frequencia.php) — Responsável só pode ver o próprio
     * dependente.
     */
    public function historicoFrequencia(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (empty(array_intersect(['Administrador', 'Diretoria', 'Professor', 'Responsável', 'Responsavel'], $funcoes))) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $alunoId = $request->query('aluno_id');
        $anoLetivo = $request->query('ano', now()->year);

        if (!$alunoId) {
            return response()->json(['error' => 'O ID do aluno é obrigatório.'], 400);
        }

        $ehResponsavelSomente = (in_array('Responsável', $funcoes, true) || in_array('Responsavel', $funcoes, true))
            && empty(array_intersect(['Administrador', 'Diretoria', 'Professor'], $funcoes));

        if ($ehResponsavelSomente) {
            $temDependente = DB::table('responsaveis as r')
                ->join('aluno_responsavel as ar', 'r.id', '=', 'ar.responsavel_id')
                ->where('r.usuario_id', (int) ($jwtUser->id ?? 0))
                ->where('ar.aluno_id', $alunoId)
                ->exists();

            if (!$temDependente) {
                return response()->json(['error' => 'Acesso negado. Este aluno não é seu dependente.'], 403);
            }
        }

        $dados = DB::selectOne('
            SELECT
                u.nome_completo, a.matricula, t.nome_turma,
                COUNT(f.id) AS total_dias,
                SUM(CASE WHEN f.presente = 1 THEN 1 ELSE 0 END) AS total_presencas,
                SUM(CASE WHEN f.presente = 0 THEN 1 ELSE 0 END) AS total_faltas
            FROM alunos a
            JOIN usuarios u ON a.usuario_id = u.id
            LEFT JOIN turma_aluno ta ON a.id = ta.aluno_id
            LEFT JOIN turmas t ON ta.turma_id = t.id
            LEFT JOIN frequencia f ON a.id = f.aluno_id AND YEAR(f.data) = ?
            WHERE a.id = ?
            GROUP BY a.id, u.nome_completo, a.matricula, t.id, t.nome_turma
        ', [$anoLetivo, $alunoId]);

        if (!$dados) {
            return response()->json(['error' => 'Aluno não encontrado.'], 404);
        }

        $total = (int) $dados->total_dias;
        $presencas = (int) $dados->total_presencas;
        $porcentagem = $total > 0 ? round(($presencas / $total) * 100, 2) : 0;

        return response()->json([
            'success' => true,
            'data'    => [
                'aluno'      => $dados->nome_completo,
                'matricula'  => $dados->matricula,
                'turma'      => $dados->nome_turma ?? 'Não vinculado',
                'ano_letivo' => (int) $anoLetivo,
                'resumo'     => [
                    'presencas'                        => $presencas,
                    'faltas'                            => (int) $dados->total_faltas,
                    'total_dias_letivos_registrados'    => $total,
                    'porcentagem_frequencia'            => $porcentagem . '%',
                ],
            ],
        ]);
    }

    /**
     * Lançamento/correção da chamada do dia (equivalente a
     * salvar_frequencia.php) — upsert em `frequencia` (UNIQUE aluno_id+data
     * já existe no banco, diferente da situação encontrada em `notas`).
     */
    public function salvarFrequencia(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $username = $jwtUser->nome ?? 'desconhecido';

        $dataFrequencia = $request->input('data');
        $alunosFrequencia = $request->input('alunos', []);

        if (!$dataFrequencia || empty($alunosFrequencia)) {
            return response()->json(['error' => 'Dados incompletos. Informe a data e a lista de alunos.'], 400);
        }

        DB::transaction(function () use ($alunosFrequencia, $dataFrequencia) {
            foreach ($alunosFrequencia as $item) {
                DB::statement('
                    INSERT INTO frequencia (aluno_id, data, presente, justificativa)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE presente = VALUES(presente), justificativa = VALUES(justificativa)
                ', [
                    $item['aluno_id'],
                    $dataFrequencia,
                    !empty($item['presente']) ? 1 : 0,
                    $item['justificativa'] ?? null,
                ]);
            }
        });

        $totalAlunos = count($alunosFrequencia);

        $this->log->registrar(
            $request, $usuarioId, $username, 'LANCAMENTO_FREQUENCIA', '/api/professores/frequencia', 'POST',
            "Frequência lançada para $totalAlunos aluno(s) na data $dataFrequencia",
            200,
            ['data' => $dataFrequencia, 'total_alunos' => $totalAlunos]
        );

        return response()->json([
            'success' => true,
            'message' => "Frequência salva/atualizada com sucesso para $totalAlunos alunos.",
        ]);
    }
}
