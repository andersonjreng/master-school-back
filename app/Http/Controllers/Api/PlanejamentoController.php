<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Planejamento\MaxPlanejamentoService;
use App\Services\Planejamento\PlanejamentoService;
use App\Support\Papeis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Planejamento de aulas. Professor planeja as turmas/disciplinas dele; a
 * gestão (Administrador/Diretoria) vê todas e, se a aprovação estiver ligada
 * nas Parametrizações, aprova ou devolve os planos.
 */
class PlanejamentoController extends Controller
{
    public function __construct(
        private PlanejamentoService $planejamento,
        private MaxPlanejamentoService $max,
    ) {}

    public function opcoes(Request $request): JsonResponse
    {
        [$prof, $gestor, $negado] = $this->escopo($request);
        if ($negado) return $negado;
        return response()->json(['success' => true, 'data' => $this->planejamento->opcoes($prof) + ['gestor' => $gestor]]);
    }

    public function periodo(Request $request): JsonResponse
    {
        [$prof, , $negado] = $this->escopo($request);
        if ($negado) return $negado;
        $turmaId = (int) $request->query('turma_id');
        $de = (string) $request->query('de');
        $ate = (string) $request->query('ate');
        if (!$turmaId || !$this->dataValida($de) || !$this->dataValida($ate) || $de > $ate) {
            return response()->json(['error' => 'Informe a turma e o período.'], 400);
        }
        if ($prof && !$this->planejamento->disciplinasPermitidas($turmaId, $prof)) {
            return response()->json(['error' => 'Você não leciona nesta turma.'], 403);
        }
        $disc = $request->query('disciplina_id') ? (int) $request->query('disciplina_id') : null;
        return response()->json(['success' => true, 'data' => $this->planejamento->periodo($turmaId, $de, $ate, $prof, $disc)]);
    }

    public function bncc(Request $request): JsonResponse
    {
        [$prof, , $negado] = $this->escopo($request);
        if ($negado) return $negado;
        $turmaId = (int) $request->query('turma_id');
        $discId = (int) $request->query('disciplina_id');
        if (!$turmaId || !$discId) {
            return response()->json(['error' => 'Informe a turma e a disciplina.'], 400);
        }
        $dados = $this->planejamento->habilidadesDaTurma($turmaId, $discId, $request->query('q'), 60);
        return response()->json(['success' => true, 'data' => $dados]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->salvar($request, null);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->salvar($request, $id);
    }

    private function salvar(Request $request, ?int $id): JsonResponse
    {
        [$prof, $gestor, $negado] = $this->escopo($request);
        if ($negado) return $negado;
        $user = $request->attributes->get('max_user');
        $r = $this->planejamento->salvar($request->all(), $id, $prof, (int) ($user->id ?? 0), $gestor);
        return is_string($r)
            ? response()->json(['error' => $r], 422)
            : response()->json(['success' => true, 'data' => $r], $id ? 200 : 201);
    }

    /** Vários planos de uma vez (ex.: rascunho do Max revisado). */
    public function lote(Request $request): JsonResponse
    {
        [$prof, $gestor, $negado] = $this->escopo($request);
        if ($negado) return $negado;
        $user = $request->attributes->get('max_user');
        $salvos = [];
        $erros = [];
        try {
            DB::transaction(function () use ($request, $prof, $gestor, $user, &$salvos, &$erros) {
            foreach ((array) $request->input('planos', []) as $i => $p) {
                $r = $this->planejamento->salvar((array) $p, null, $prof, (int) ($user->id ?? 0), $gestor);
                if (is_string($r)) {
                    $erros[] = 'Aula ' . ($i + 1) . ": $r";
                } else {
                    $salvos[] = $r;
                }
            }
            if ($erros) {
                throw new \RuntimeException(implode(' ', $erros));
            }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true, 'data' => $salvos], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        [$prof, , $negado] = $this->escopo($request);
        if ($negado) return $negado;
        $erro = $this->planejamento->excluir($id, $prof);
        return $erro ? response()->json(['error' => $erro], 422) : response()->json(['success' => true]);
    }

    public function enviar(Request $request, int $id): JsonResponse
    {
        [$prof, , $negado] = $this->escopo($request);
        if ($negado) return $negado;
        $r = $this->planejamento->enviar($id, $prof);
        return is_string($r) ? response()->json(['error' => $r], 422) : response()->json(['success' => true, 'data' => $r]);
    }

    public function dada(Request $request, int $id): JsonResponse
    {
        [$prof, , $negado] = $this->escopo($request);
        if ($negado) return $negado;
        $r = $this->planejamento->marcarDada($id, $prof, $request->boolean('confirmar_dia_nao_letivo'));
        if (is_string($r) && str_starts_with($r, 'dia_nao_letivo:')) {
            return response()->json(['codigo' => 'dia_nao_letivo', 'message' => substr($r, strlen('dia_nao_letivo:'))], 409);
        }
        return is_string($r) ? response()->json(['error' => $r], 422) : response()->json(['success' => true, 'data' => $r]);
    }

    public function gerar(Request $request): JsonResponse
    {
        [$prof, , $negado] = $this->escopo($request);
        if ($negado) return $negado;
        $turmaId = (int) $request->input('turma_id');
        $discId = (int) $request->input('disciplina_id');
        $conteudo = trim((string) $request->input('conteudo'));
        $aPartirDe = (string) $request->input('a_partir_de', now()->toDateString());
        if (!$turmaId || !$discId || $conteudo === '' || !$this->dataValida($aPartirDe)) {
            return response()->json(['error' => 'Informe turma, disciplina, o conteúdo e a data de início.'], 400);
        }
        if (!in_array($discId, $this->planejamento->disciplinasPermitidas($turmaId, $prof), true)) {
            return response()->json(['error' => 'Você não leciona esta disciplina nesta turma.'], 403);
        }
        try {
            $r = $this->max->gerar($request->attributes->get('max_user'), $turmaId, $discId, mb_substr($conteudo, 0, 1500),
                (int) $request->input('quantidade', 3), $aPartirDe, $request->input('observacoes'));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true, 'data' => $r]);
    }

    // ── Gestão ─────────────────────────────────────────────────────────────

    public function pendentes(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->planejamento->pendentes()]);
    }

    public function aprovar(Request $request, int $id): JsonResponse
    {
        return $this->decidir($request, $id, true);
    }

    public function devolver(Request $request, int $id): JsonResponse
    {
        return $this->decidir($request, $id, false);
    }

    private function decidir(Request $request, int $id, bool $aprovar): JsonResponse
    {
        $user = $request->attributes->get('max_user');
        $r = $this->planejamento->decidir($id, $aprovar, $request->input('comentario'), (int) ($user->id ?? 0));
        return is_string($r) ? response()->json(['error' => $r], 422) : response()->json(['success' => true, 'data' => $r]);
    }

    /** Parametrizações → Pedagógico */
    public function configuracao(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['aprovacao' => $this->planejamento->aprovacaoAtiva()]]);
    }

    public function salvarConfiguracao(Request $request): JsonResponse
    {
        $this->planejamento->definirAprovacao($request->boolean('aprovacao'));
        return response()->json(['success' => true, 'data' => ['aprovacao' => $this->planejamento->aprovacaoAtiva()]]);
    }

    // ── Auxiliares ─────────────────────────────────────────────────────────

    /** @return array{0: ?int, 1: bool, 2: ?JsonResponse} professor_id do recorte, é gestão?, resposta de negação */
    private function escopo(Request $request): array
    {
        $user = $request->attributes->get('max_user');
        $funcoes = (array) ($user->funcoes ?? $user->roles ?? []);
        if (Papeis::ehGestor($funcoes)) {
            return [null, true, null];
        }
        $professorId = DB::table('professores')->where('usuario_id', (int) ($user->id ?? 0))->value('id');
        if (!$professorId) {
            return [null, false, response()->json(['error' => 'Acesso negado.'], 403)];
        }
        return [(int) $professorId, false, null];
    }

    private function dataValida(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }
}
