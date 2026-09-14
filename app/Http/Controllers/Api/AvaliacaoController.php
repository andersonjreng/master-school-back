<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Avaliacoes\ProfessorResolver;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/avaliacoes/*.php (CRUD da avaliação em si — notas ficam
 * em NotaController). Não portados: get_frequencia_anual.php e
 * post_frequencia.php (referenciam colunas que não existem na tabela
 * `frequencia` atual — dead code, sem uso no frontend) e post_avaliacao_bckp.php
 * (backup não usado).
 */
class AvaliacaoController extends Controller
{
    public function __construct(
        private ProfessorResolver $professores,
        private LogSistemaService $log,
    ) {
    }

    public function tiposAvaliacao(): JsonResponse
    {
        $tipos = DB::table('tipos_avaliacao')
            ->orderByDesc('peso')
            ->orderBy('nome_tipo')
            ->get(['id', 'nome_tipo', 'peso']);

        return response()->json(['success' => true, 'count' => $tipos->count(), 'data' => $tipos]);
    }

    public function unidadesLetivas(Request $request): JsonResponse
    {
        $anoLetivo = $request->query('ano_letivo');
        $anoLetivo = ($anoLetivo && is_numeric($anoLetivo)) ? (int) $anoLetivo : null;

        $query = DB::table('unidades_letivas')->orderBy('id');
        if ($anoLetivo) {
            $query->where('ano_letivo', $anoLetivo);
        }
        $unidades = $query->get(['id', 'nome_unidade', 'ano_letivo']);

        return response()->json(['success' => true, 'count' => $unidades->count(), 'data' => $unidades]);
    }

    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        $isAdmin = in_array('Administrador', $funcoes, true);
        $isProfessor = in_array('Professor', $funcoes, true);
        $isResponsavel = in_array('Responsável', $funcoes, true) || in_array('Responsavel', $funcoes, true);

        $wheres = [];
        $params = [];

        if ($isProfessor) {
            $professorId = $this->professores->idDoUsuario($usuarioId);
            if (!$professorId) {
                return response()->json(['error' => 'Acesso negado.', 'message' => 'Registro de professor não encontrado.'], 403);
            }
            $wheres[] = 'a.professor_id = ?';
            $params[] = $professorId;
        } elseif ($isResponsavel) {
            $turmaIdReq = $request->query('turma_id');
            if (!$turmaIdReq || !is_numeric($turmaIdReq)) {
                return response()->json(['error' => 'Parâmetro turma_id é obrigatório para responsáveis.'], 400);
            }

            $temDependente = DB::table('responsaveis as r')
                ->join('aluno_responsavel as ar', 'r.id', '=', 'ar.responsavel_id')
                ->join('turma_aluno as ta', 'ar.aluno_id', '=', 'ta.aluno_id')
                ->where('r.usuario_id', $usuarioId)
                ->where('ta.turma_id', $turmaIdReq)
                ->exists();

            if (!$temDependente) {
                return response()->json(['error' => 'Acesso negado.', 'message' => 'Nenhum dependente seu está matriculado nesta turma.'], 403);
            }
            $wheres[] = 'a.turma_id = ?';
            $params[] = (int) $turmaIdReq;
        } elseif (!$isAdmin) {
            return response()->json(['error' => 'Acesso negado.', 'message' => 'Você não tem permissão para listar avaliações.'], 403);
        }

        $turmaId = $request->query('turma_id');
        if ($turmaId && is_numeric($turmaId) && !$isResponsavel) {
            $wheres[] = 'a.turma_id = ?';
            $params[] = (int) $turmaId;
        }

        $dataInicio = $request->query('data_inicio');
        $dataFim = $request->query('data_fim');
        if ($dataInicio && $dataFim) {
            if (!strtotime($dataInicio) || !strtotime($dataFim)) {
                return response()->json(['error' => 'Parâmetros de data inválidos.', 'message' => 'O formato das datas deve ser compatível com SQL (AAAA-MM-DD).'], 400);
            }
            $wheres[] = 'a.data_aplicacao BETWEEN ? AND ?';
            $params[] = $dataInicio;
            $params[] = $dataFim;
        }

        $whereClause = $wheres ? ('WHERE ' . implode(' AND ', $wheres)) : '';

        $turmaInfo = null;
        if ($turmaId) {
            $turmaInfo = DB::selectOne('
                SELECT sistema_avaliacao_id, etapa_bncc, nome_turma, sa.nome_sistema
                FROM turmas t
                LEFT JOIN sistemas_avaliacao sa ON t.sistema_avaliacao_id = sa.id
                WHERE t.id = ?
            ', [$turmaId]);
        }

        $avaliacoes = DB::select("
            SELECT
                a.id AS avaliacao_id, a.descricao, a.data_aplicacao, a.valor_maximo, a.data_cadastro,
                a.turma_id, t.nome_turma, t.ano_letivo,
                a.disciplina_id, d.nome_disciplina AS nome_disciplina,
                a.tipo_avaliacao_id, ta.nome_tipo AS tipo_avaliacao, ta.peso,
                a.unidade_letiva_id, ul.nome_unidade AS unidade_letiva,
                a.professor_id, up.nome_completo AS nome_professor
            FROM avaliacoes a
            JOIN turmas t ON a.turma_id = t.id
            JOIN disciplinas d ON a.disciplina_id = d.id
            JOIN tipos_avaliacao ta ON a.tipo_avaliacao_id = ta.id
            LEFT JOIN unidades_letivas ul ON a.unidade_letiva_id = ul.id
            JOIN professores p ON a.professor_id = p.id
            JOIN usuarios up ON p.usuario_id = up.id
            $whereClause
            ORDER BY a.data_aplicacao DESC, t.nome_turma ASC
        ", $params);

        return response()->json([
            'success'    => true,
            'count'      => count($avaliacoes),
            'data'       => $avaliacoes,
            'turma_info' => $turmaInfo,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $username = $jwtUser->nome ?? 'desconhecido';
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $isProfessor = in_array('Professor', $funcoes, true);
        $isAdmin = in_array('Administrador', $funcoes, true) || in_array('Diretor', $funcoes, true);

        if (!$isProfessor && !$isAdmin) {
            return response()->json(['error' => 'Acesso negado.', 'message' => 'Apenas professores, administradores ou diretores podem cadastrar avaliações.'], 403);
        }

        $turmaId = $request->input('turma_id');
        $disciplinaId = $request->input('disciplina_id');
        $tipoAvaliacaoId = $request->input('tipo_avaliacao_id');
        $unidadeLetivaId = $request->input('unidade_letiva_id');
        $descricao = $request->input('descricao');
        $dataAplicacao = $request->input('data_aplicacao');
        $valorMaximo = $request->input('valor_maximo');

        $professorIdFinal = null;
        if ($isProfessor) {
            $professorIdFinal = $this->professores->idDoUsuario($usuarioId);
            if (!$professorIdFinal) {
                return response()->json(['error' => 'Erro de sistema.', 'message' => 'Professor logado não possui registro na tabela de professores.'], 403);
            }
        } elseif ($isAdmin) {
            $professorIdFinal = $request->input('professor_id');
            if (!$professorIdFinal) {
                return response()->json(['error' => 'Professor responsável obrigatório.', 'message' => 'Como Administrador/Diretor, você deve especificar o ID do professor (campo professor_id) que será o responsável pela avaliação.'], 400);
            }
        }

        if (!$turmaId || !$disciplinaId || !$tipoAvaliacaoId || !$descricao || !$dataAplicacao || !$valorMaximo || !$unidadeLetivaId) {
            return response()->json(['error' => 'Campos obrigatórios faltando.', 'message' => 'Turma, Disciplina, Tipo, Unidade Letiva, Descrição, Data de Aplicação e Valor Máximo são obrigatórios.'], 400);
        }

        $duplicada = DB::table('avaliacoes')
            ->where('turma_id', $turmaId)
            ->where('disciplina_id', $disciplinaId)
            ->where('tipo_avaliacao_id', $tipoAvaliacaoId)
            ->where('unidade_letiva_id', $unidadeLetivaId)
            ->exists();

        if ($duplicada) {
            return response()->json([
                'error'   => 'Avaliação Duplicada',
                'message' => 'Já existe uma avaliação cadastrada com o mesmo Tipo, Disciplina, Turma e Unidade Letiva (Bimestre).',
            ], 409);
        }

        $erroPeso = $this->validarTetoDePeso($tipoAvaliacaoId, $turmaId, $disciplinaId, $unidadeLetivaId);
        if ($erroPeso) {
            return $erroPeso;
        }

        $novoId = DB::table('avaliacoes')->insertGetId([
            'turma_id'           => $turmaId,
            'disciplina_id'      => $disciplinaId,
            'tipo_avaliacao_id'  => $tipoAvaliacaoId,
            'professor_id'       => $professorIdFinal,
            'descricao'          => $descricao,
            'data_aplicacao'     => $dataAplicacao,
            'valor_maximo'       => $valorMaximo,
            'unidade_letiva_id'  => $unidadeLetivaId,
            'data_cadastro'      => now(),
        ]);

        $this->log->registrar(
            $request, $usuarioId, $username, 'CRIACAO_AVALIACAO', '/api/avaliacoes', 'POST',
            "Avaliação #$novoId criada: \"$descricao\" (turma #$turmaId, disciplina #$disciplinaId)",
            201,
            ['avaliacao_id' => $novoId, 'turma_id' => $turmaId, 'disciplina_id' => $disciplinaId, 'tipo_avaliacao_id' => $tipoAvaliacaoId]
        );

        return response()->json([
            'success'                   => 'Avaliação cadastrada com sucesso.',
            'avaliacao_id'              => $novoId,
            'professor_responsavel_id'  => $professorIdFinal,
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $username = $jwtUser->nome ?? 'desconhecido';
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $isProfessor = in_array('Professor', $funcoes, true);
        $isAdmin = in_array('Administrador', $funcoes, true) || in_array('Diretor', $funcoes, true);

        if (!$isProfessor && !$isAdmin) {
            return response()->json(['error' => 'Acesso negado.', 'message' => 'Apenas professores, administradores ou diretores podem editar avaliações.'], 403);
        }

        $avaliacaoId = $request->query('avaliacao_id');
        if (!$avaliacaoId || !is_numeric($avaliacaoId)) {
            return response()->json(['error' => 'Parâmetro obrigatório.', 'message' => 'avaliacao_id é obrigatório na query string.'], 400);
        }
        $avaliacaoId = (int) $avaliacaoId;

        $avaliacaoAtual = DB::table('avaliacoes')->where('id', $avaliacaoId)
            ->first(['turma_id', 'disciplina_id', 'tipo_avaliacao_id', 'unidade_letiva_id', 'professor_id']);

        if (!$avaliacaoAtual) {
            return response()->json(['error' => 'Avaliação não encontrada.'], 404);
        }

        $notasCount = DB::table('notas')->where('avaliacao_id', $avaliacaoId)->count();

        $professorIdLogado = $isProfessor ? $this->professores->idDoUsuario($usuarioId) : null;

        if (!$isAdmin && $isProfessor) {
            if (!$professorIdLogado || (int) $avaliacaoAtual->professor_id !== $professorIdLogado) {
                return response()->json(['error' => 'Acesso negado.', 'message' => 'Você só pode editar avaliações das quais é o professor responsável.'], 403);
            }
            if ($notasCount > 0) {
                return response()->json(['error' => 'Acesso negado.', 'message' => 'Esta avaliação já possui notas lançadas. Apenas um administrador pode editá-la.'], 403);
            }
        }

        $disciplinaId = $request->input('disciplina_id');
        $tipoAvaliacaoId = $request->input('tipo_avaliacao_id');
        $unidadeLetivaId = $request->input('unidade_letiva_id');
        $descricao = $request->input('descricao');
        $dataAplicacao = $request->input('data_aplicacao');
        $valorMaximo = $request->input('valor_maximo');

        if (!$disciplinaId || !$tipoAvaliacaoId || !$descricao || !$dataAplicacao || !$valorMaximo || !$unidadeLetivaId) {
            return response()->json(['error' => 'Campos obrigatórios faltando.', 'message' => 'Disciplina, Tipo, Unidade Letiva, Descrição, Data de Aplicação e Valor Máximo são obrigatórios.'], 400);
        }

        $turmaId = (int) $avaliacaoAtual->turma_id;

        if ($isAdmin) {
            $professorIdFinal = $request->input('professor_id', $avaliacaoAtual->professor_id);
            if (!$professorIdFinal) {
                return response()->json(['error' => 'Professor responsável obrigatório.', 'message' => 'Informe o professor responsável (campo professor_id).'], 400);
            }
        } else {
            $professorIdFinal = $avaliacaoAtual->professor_id;
        }

        $duplicada = DB::table('avaliacoes')
            ->where('turma_id', $turmaId)
            ->where('disciplina_id', $disciplinaId)
            ->where('tipo_avaliacao_id', $tipoAvaliacaoId)
            ->where('unidade_letiva_id', $unidadeLetivaId)
            ->where('id', '<>', $avaliacaoId)
            ->exists();

        if ($duplicada) {
            return response()->json([
                'error'   => 'Avaliação Duplicada',
                'message' => 'Já existe outra avaliação cadastrada com o mesmo Tipo, Disciplina, Turma e Unidade Letiva (Bimestre).',
            ], 409);
        }

        $erroPeso = $this->validarTetoDePeso($tipoAvaliacaoId, $turmaId, $disciplinaId, $unidadeLetivaId, $avaliacaoId);
        if ($erroPeso) {
            return $erroPeso;
        }

        DB::table('avaliacoes')->where('id', $avaliacaoId)->update([
            'disciplina_id'      => $disciplinaId,
            'tipo_avaliacao_id'  => $tipoAvaliacaoId,
            'unidade_letiva_id'  => $unidadeLetivaId,
            'professor_id'       => $professorIdFinal,
            'descricao'          => $descricao,
            'data_aplicacao'     => $dataAplicacao,
            'valor_maximo'       => $valorMaximo,
        ]);

        $this->log->registrar(
            $request, $usuarioId, $username, 'ATUALIZACAO_AVALIACAO', '/api/avaliacoes', 'PUT',
            "Avaliação #$avaliacaoId atualizada: \"$descricao\"",
            200,
            ['avaliacao_id' => $avaliacaoId, 'turma_id' => $turmaId, 'disciplina_id' => $disciplinaId, 'tipo_avaliacao_id' => $tipoAvaliacaoId]
        );

        return response()->json(['success' => 'Avaliação atualizada com sucesso.', 'avaliacao_id' => $avaliacaoId]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $username = $jwtUser->nome ?? 'desconhecido';
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $isProfessor = in_array('Professor', $funcoes, true);
        $isAdmin = in_array('Administrador', $funcoes, true) || in_array('Diretor', $funcoes, true);

        if (!$isProfessor && !$isAdmin) {
            return response()->json(['error' => 'Acesso negado.', 'message' => 'Apenas professores, administradores ou diretores podem excluir avaliações.'], 403);
        }

        $avaliacaoId = $request->query('avaliacao_id');
        if (!$avaliacaoId || !is_numeric($avaliacaoId)) {
            return response()->json(['error' => 'Parâmetro obrigatório.', 'message' => 'avaliacao_id é obrigatório na query string.'], 400);
        }
        $avaliacaoId = (int) $avaliacaoId;

        $avaliacaoAtual = DB::table('avaliacoes')->where('id', $avaliacaoId)->first(['descricao', 'turma_id', 'professor_id']);
        if (!$avaliacaoAtual) {
            return response()->json(['error' => 'Avaliação não encontrada.'], 404);
        }

        $notasCount = DB::table('notas')->where('avaliacao_id', $avaliacaoId)->count();
        if ($notasCount > 0) {
            return response()->json(['error' => 'Acesso negado.', 'message' => 'Não é possível excluir uma avaliação que já possui notas lançadas.'], 403);
        }

        if (!$isAdmin && $isProfessor) {
            $professorIdLogado = $this->professores->idDoUsuario($usuarioId);
            if (!$professorIdLogado || (int) $avaliacaoAtual->professor_id !== $professorIdLogado) {
                return response()->json(['error' => 'Acesso negado.', 'message' => 'Você só pode excluir avaliações das quais é o professor responsável.'], 403);
            }
        }

        DB::table('avaliacoes')->where('id', $avaliacaoId)->delete();

        $this->log->registrar(
            $request, $usuarioId, $username, 'EXCLUSAO_AVALIACAO', '/api/avaliacoes', 'DELETE',
            "Avaliação #$avaliacaoId excluída: \"{$avaliacaoAtual->descricao}\"",
            200,
            ['avaliacao_id' => $avaliacaoId, 'turma_id' => $avaliacaoAtual->turma_id]
        );

        return response()->json(['success' => 'Avaliação excluída com sucesso.']);
    }

    /**
     * Teto de 100% de peso por turma+disciplina+bimestre — equivalente ao bloco
     * repetido em post_avaliacao.php e update_avaliacao.php.
     */
    private function validarTetoDePeso(int $tipoAvaliacaoId, int $turmaId, int $disciplinaId, int $unidadeLetivaId, ?int $excetoAvaliacaoId = null): ?JsonResponse
    {
        $pesoNovo = DB::table('tipos_avaliacao')->where('id', $tipoAvaliacaoId)->value('peso');
        if ($pesoNovo === null) {
            return response()->json(['error' => 'Tipo inválido.', 'message' => 'O tipo_avaliacao_id informado não existe.'], 400);
        }
        $pesoNovo = (float) $pesoNovo;

        $query = DB::table('avaliacoes as av')
            ->join('tipos_avaliacao as ta', 'av.tipo_avaliacao_id', '=', 'ta.id')
            ->where('av.turma_id', $turmaId)
            ->where('av.disciplina_id', $disciplinaId)
            ->where('av.unidade_letiva_id', $unidadeLetivaId);

        if ($excetoAvaliacaoId) {
            $query->where('av.id', '<>', $excetoAvaliacaoId);
        }

        $pesoAtual = (float) ($query->sum('ta.peso') ?? 0);

        if (($pesoAtual + $pesoNovo) > 100) {
            return response()->json([
                'error'   => 'Limite de peso excedido.',
                'message' => "O peso total das avaliações desta disciplina/bimestre já é {$pesoAtual}% (máximo 100%). Não é possível salvar uma avaliação com peso {$pesoNovo}%.",
            ], 409);
        }

        return null;
    }
}
