<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/bncc/*.php — avaliação de educação infantil por
 * habilidades da BNCC (Base Nacional Comum Curricular), alternativa ao
 * sistema de notas numéricas pra turmas com sistema_avaliacao_id = 3.
 *
 * Fix vs. legado: get_status_alunos_bncc.php, get_registros_bncc_aluno.php e
 * post_registros_bncc.php checavam o papel 'Diretor', que nunca existiu na
 * tabela funcoes (o papel real é 'Diretoria', usado em todo o resto do
 * sistema) — na prática, usuários com papel Diretoria nunca caíam no
 * `$is_admin` desses 3 endpoints. Corrigido pra 'Diretoria', consistente com
 * o resto da aplicação.
 */
class BnccController extends Controller
{
    public function semestres(Request $request): JsonResponse
    {
        $anoLetivo = $request->query('ano_letivo');
        $anoLetivo = ($anoLetivo !== null && is_numeric($anoLetivo)) ? (int) $anoLetivo : null;

        $query = DB::table('semestres')->select('id', 'nome', 'ano_letivo', 'data_inicio', 'data_fim');
        if ($anoLetivo) {
            $query->where('ano_letivo', $anoLetivo)->orderBy('id');
        } else {
            $query->orderByDesc('ano_letivo')->orderBy('id');
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function habilidades(Request $request): JsonResponse
    {
        $etapa = trim((string) $request->query('etapa', ''));
        if (!$etapa) {
            return response()->json([
                'error' => 'Parâmetro obrigatório.',
                'message' => 'Informe a etapa (ex: Bebês, Crianças bem pequenas, Crianças pequenas).',
            ], 400);
        }

        $habilidades = DB::table('habilidades_bncc')
            ->where('etapa', $etapa)
            ->where('ativo', 1)
            ->orderBy('campo_experiencia')
            ->orderBy('ordem')
            ->get(['id', 'etapa', 'campo_experiencia', 'codigo', 'descricao', 'ordem']);

        return response()->json(['success' => true, 'data' => $habilidades, 'total' => $habilidades->count()]);
    }

    public function registrosAluno(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        $isAdmin = !empty(array_intersect(['Administrador', 'Diretoria'], $funcoes));
        $isProfessor = in_array('Professor', $funcoes, true);
        $isResponsavel = in_array('Responsável', $funcoes, true);

        if (!$isAdmin && !$isProfessor && !$isResponsavel) {
            return response()->json(['error' => 'Acesso negado.', 'message' => 'Você não tem permissão para consultar avaliações BNCC.'], 403);
        }

        $alunoId = $request->query('aluno_id');
        $semestreId = $request->query('semestre_id');
        if (!$alunoId || !is_numeric($alunoId) || !$semestreId || !is_numeric($semestreId)) {
            return response()->json(['error' => 'Parâmetros obrigatórios.', 'message' => 'aluno_id e semestre_id são obrigatórios.'], 400);
        }
        $alunoId = (int) $alunoId;
        $semestreId = (int) $semestreId;

        if ($isResponsavel && !$isAdmin) {
            $autorizado = DB::table('responsaveis as r')
                ->join('aluno_responsavel as ar', 'r.id', '=', 'ar.responsavel_id')
                ->where('r.usuario_id', $usuarioIdLogado)
                ->where('ar.aluno_id', $alunoId)
                ->exists();

            if (!$autorizado) {
                return response()->json(['error' => 'Acesso negado.', 'message' => 'Este aluno não é seu dependente.'], 403);
            }
        }

        $registros = DB::table('registros_bncc')
            ->where('aluno_id', $alunoId)
            ->where('semestre_id', $semestreId)
            ->get(['habilidade_id', 'nivel', 'observacao'])
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['nivel'] = (int) $row->nivel;

                return $arr;
            });

        return response()->json(['success' => true, 'data' => $registros]);
    }

    public function statusAlunos(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        $isAdmin = !empty(array_intersect(['Administrador', 'Diretoria'], $funcoes));
        $isProfessor = in_array('Professor', $funcoes, true);
        $isResponsavel = in_array('Responsável', $funcoes, true);

        if (!$isAdmin && !$isProfessor && !$isResponsavel) {
            return response()->json(['error' => 'Acesso negado.', 'message' => 'Você não tem permissão para consultar avaliações BNCC.'], 403);
        }

        $turmaId = $request->query('turma_id');
        $semestreId = $request->query('semestre_id');
        if (!$turmaId || !is_numeric($turmaId) || !$semestreId || !is_numeric($semestreId)) {
            return response()->json(['error' => 'Parâmetros obrigatórios.', 'message' => 'turma_id e semestre_id são obrigatórios.'], 400);
        }
        $turmaId = (int) $turmaId;
        $semestreId = (int) $semestreId;

        $dependentesIds = null;
        if ($isResponsavel && !$isAdmin) {
            $dependentesIds = DB::table('responsaveis as r')
                ->join('aluno_responsavel as ar', 'r.id', '=', 'ar.responsavel_id')
                ->join('turma_aluno as ta', 'ar.aluno_id', '=', 'ta.aluno_id')
                ->where('r.usuario_id', $usuarioIdLogado)
                ->where('ta.turma_id', $turmaId)
                ->pluck('ar.aluno_id')
                ->unique()
                ->values();

            if ($dependentesIds->isEmpty()) {
                return response()->json(['error' => 'Acesso negado.', 'message' => 'Nenhum dependente seu está matriculado nesta turma.'], 403);
            }
        }

        $etapaBncc = DB::table('turmas')->where('id', $turmaId)->value('etapa_bncc');

        $totalHabilidades = 0;
        if ($etapaBncc) {
            $totalHabilidades = DB::table('habilidades_bncc')->where('etapa', $etapaBncc)->where('ativo', 1)->count();
        }

        $query = DB::table('turma_aluno as ta')
            ->join('alunos as a', 'ta.aluno_id', '=', 'a.id')
            ->join('usuarios as u', 'a.usuario_id', '=', 'u.id')
            ->leftJoin('registros_bncc as rb', function ($join) use ($semestreId, $turmaId) {
                $join->on('rb.aluno_id', '=', 'a.id')
                    ->where('rb.semestre_id', $semestreId)
                    ->where('rb.turma_id', $turmaId);
            })
            ->where('ta.turma_id', $turmaId)
            ->groupBy('a.id', 'u.nome_completo', 'a.matricula')
            ->orderBy('u.nome_completo')
            ->select(['a.id as aluno_id', 'u.nome_completo as nome_aluno', 'a.matricula', DB::raw('COUNT(rb.id) as registros_count')]);

        if ($dependentesIds !== null) {
            $query->whereIn('ta.aluno_id', $dependentesIds);
        }

        $alunos = $query->get()->map(function ($row) use ($totalHabilidades) {
            $arr = (array) $row;
            $arr['registros_count'] = (int) $row->registros_count;
            $arr['total_habilidades'] = $totalHabilidades;

            return $arr;
        });

        return response()->json(['success' => true, 'data' => $alunos, 'total_habilidades' => $totalHabilidades]);
    }

    public function storeRegistros(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        $isProfessor = in_array('Professor', $funcoes, true);
        $isAdmin = !empty(array_intersect(['Administrador', 'Diretoria'], $funcoes));

        if (!$isProfessor && !$isAdmin) {
            return response()->json(['error' => 'Acesso negado.', 'message' => 'Apenas professores ou administradores podem registrar avaliações BNCC.'], 403);
        }

        $alunoId = $request->input('aluno_id');
        $semestreId = $request->input('semestre_id');
        $turmaId = $request->input('turma_id');
        $registros = $request->input('registros', []);

        if (!$alunoId || !$semestreId || !$turmaId) {
            return response()->json(['error' => 'Dados obrigatórios faltando.', 'message' => 'aluno_id, semestre_id e turma_id são obrigatórios.'], 400);
        }
        if (empty($registros)) {
            return response()->json(['error' => 'Sem registros.', 'message' => 'Nenhum registro de habilidade foi enviado.'], 400);
        }

        $professorId = null;
        if ($isProfessor) {
            $professorId = DB::table('professores')->where('usuario_id', $usuarioId)->value('id');
        }

        $salvos = 0;
        try {
            DB::transaction(function () use ($registros, $alunoId, $semestreId, $turmaId, $professorId, &$salvos) {
                foreach ($registros as $registro) {
                    $habilidadeId = $registro['habilidade_id'] ?? null;
                    $nivel = $registro['nivel'] ?? null;
                    $observacao = $registro['observacao'] ?? '';

                    if (!$habilidadeId || !in_array($nivel, [1, 2, 3], true)) {
                        continue; // Pula registros inválidos (sem nível definido)
                    }

                    DB::statement('
                        INSERT INTO registros_bncc (aluno_id, habilidade_id, semestre_id, turma_id, nivel, observacao, professor_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            nivel = VALUES(nivel),
                            observacao = VALUES(observacao),
                            professor_id = VALUES(professor_id)
                    ', [$alunoId, $habilidadeId, $semestreId, $turmaId, $nivel, $observacao, $professorId]);

                    $salvos++;
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Erro ao salvar registro.', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'message' => 'Avaliação BNCC salva com sucesso.', 'salvos' => $salvos]);
    }
}
