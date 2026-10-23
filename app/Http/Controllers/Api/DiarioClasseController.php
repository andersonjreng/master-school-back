<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Diario\DiarioClasseService;
use App\Support\Papeis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Diário de Classe. Quem acompanha as turmas (pedagogico.ver) vê qualquer turma e
 * todas as disciplinas; professor, só as turmas em que leciona e as
 * disciplinas dele.
 */
class DiarioClasseController extends Controller
{
    public function __construct(private DiarioClasseService $diario) {}

    public function opcoes(Request $request): JsonResponse
    {
        [$professorId, $negado] = $this->escopo($request);
        if ($negado) {
            return $negado;
        }
        return response()->json(['success' => true, 'data' => $this->diario->opcoes($professorId)]);
    }

    public function show(Request $request): JsonResponse
    {
        [$professorId, $negado] = $this->escopo($request);
        if ($negado) {
            return $negado;
        }
        $turmaId = (int) $request->query('turma_id');
        $unidadeId = (int) $request->query('unidade_id');
        $disciplinaId = $request->query('disciplina_id') ? (int) $request->query('disciplina_id') : null;
        if (!$turmaId || !$unidadeId) {
            return response()->json(['error' => 'Informe a turma e o bimestre.'], 400);
        }
        if ($professorId && !$this->diario->leciona($professorId, $turmaId)) {
            return response()->json(['error' => 'Você não leciona nesta turma.'], 403);
        }

        $dados = $this->diario->gerar($turmaId, $unidadeId, $professorId, $disciplinaId);
        if (is_string($dados)) {
            return response()->json(['error' => $dados], 422);
        }
        return response()->json(['success' => true, 'data' => $dados]);
    }

    /** @return array{0: ?int, 1: ?JsonResponse} professor_id do recorte (null = gestão, sem recorte) */
    private function escopo(Request $request): array
    {
        $user = $request->attributes->get('max_user');
        $funcoes = (array) ($user->funcoes ?? $user->roles ?? []);
        if (\App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::PEDAGOGICO_VER)) {
            return [null, null];
        }
        $professorId = DB::table('professores')->where('usuario_id', (int) ($user->id ?? 0))->value('id');
        if (!$professorId) {
            return [null, response()->json(['error' => 'Acesso negado.'], 403)];
        }
        return [(int) $professorId, null];
    }
}
