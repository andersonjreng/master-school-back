<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Turmas\TurmaPainelService;
use App\Support\Papeis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tela Minhas Turmas: situação de hoje de cada turma e resumo de uma turma.
 * Quem acompanha as turmas ou gerencia turmas vê todas; professor, só onde leciona —
 * e só as disciplinas dele. ?minhas=1 força o recorte de professor.
 */
class TurmaSituacaoController extends Controller
{
    public function __construct(private TurmaPainelService $servico) {}

    public function situacao(Request $request): JsonResponse
    {
        [$professorId, $negado] = $this->escopo($request);
        if ($negado) {
            return $negado;
        }
        return response()->json(['success' => true, 'data' => $this->servico->situacao($professorId)]);
    }

    public function resumo(Request $request, int $id): JsonResponse
    {
        [$professorId, $negado] = $this->escopo($request);
        if ($negado) {
            return $negado;
        }
        if ($professorId && !$this->servico->leciona($professorId, $id)) {
            return response()->json(['error' => 'Você não leciona nesta turma.'], 403);
        }
        $dados = $this->servico->resumo($id, $professorId);
        return $dados
            ? response()->json(['success' => true, 'data' => $dados])
            : response()->json(['error' => 'Turma não encontrada.'], 404);
    }

    /** @return array{0: ?int, 1: ?JsonResponse} professor_id do recorte (null = todas) */
    private function escopo(Request $request): array
    {
        $user = $request->attributes->get('max_user');
        $funcoes = (array) ($user->funcoes ?? $user->roles ?? []);
        if (\App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::PEDAGOGICO_VER, \App\Support\Permissoes::TURMAS_GERENCIAR) && !$request->boolean('minhas')) {
            return [null, null];
        }
        $professorId = DB::table('professores')->where('usuario_id', (int) ($user->id ?? 0))->value('id');
        if (!$professorId) {
            return [null, response()->json(['error' => 'Acesso negado.'], 403)];
        }
        return [(int) $professorId, null];
    }
}
