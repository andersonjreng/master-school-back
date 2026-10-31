<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Agenda\AgendaService;
use App\Services\Sistema\LogSistemaService;
use App\Support\Permissoes;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agenda da Educação Infantil. Professor da turma (ou pedagogico.editar)
 * preenche; pedagogico.ver consulta; a família vê os filhos depois do envio.
 * Regras em AgendaService.
 */
class AgendaController extends Controller
{
    public function __construct(private AgendaService $svc, private LogSistemaService $log)
    {
    }

    /** O que o usuário vê: turmas (equipe), filhos (família) e se configura os itens. */
    public function inicio(Request $request): JsonResponse
    {
        if (!$this->svc->disponivel()) {
            return response()->json(['data' => ['indisponivel' => true]]);
        }
        return response()->json(['data' => $this->svc->acesso($this->usuarioId($request), $this->funcoes($request)) + ['hoje' => now()->toDateString()]]);
    }

    public function dia(Request $request, int $turmaId): JsonResponse
    {
        if ($erro = $this->semAgenda() ?? $this->dataInvalida($request)) {
            return $erro;
        }
        if (!$this->svc->podeVer($this->usuarioId($request), $this->funcoes($request), $turmaId)) {
            return response()->json(['error' => 'Turma não encontrada.'], 404);
        }
        $d = $this->svc->dia($turmaId, $this->data($request));
        $d['pode_editar'] = $this->svc->podeEditar($this->usuarioId($request), $this->funcoes($request), $turmaId);
        return response()->json(['data' => $d]);
    }

    public function salvar(Request $request, int $turmaId): JsonResponse
    {
        return $this->editar($request, $turmaId, fn ($u) => $this->svc->salvar($turmaId, $this->data($request), $request->all(), $u));
    }

    public function enviar(Request $request, int $turmaId): JsonResponse
    {
        return $this->editar($request, $turmaId, function ($u) use ($request, $turmaId) {
            $d = $this->svc->enviar($turmaId, $this->data($request), $u);
            $nome = $request->attributes->get('max_user')->nome ?? 'desconhecido';
            $this->log->registrar($request, $u, $nome, 'AGENDA', '/' . $request->path(), 'POST',
                "Agenda de {$d['turma']['nome']} de " . Carbon::parse($d['data'])->format('d/m/Y') . ' enviada às famílias', 200, []);
            return $d;
        });
    }

    public function itens(Request $request): JsonResponse
    {
        if ($erro = $this->semAgenda()) {
            return $erro;
        }
        return response()->json(['data' => $this->svc->itens(false)]);
    }

    public function salvarItens(Request $request): JsonResponse
    {
        if ($erro = $this->semAgenda()) {
            return $erro;
        }
        if (!Permissoes::pode($this->funcoes($request), Permissoes::PEDAGOGICO_EDITAR)) {
            return response()->json(['error' => 'Seu perfil não altera os itens da agenda.'], 403);
        }
        try {
            return response()->json(['success' => true, 'data' => $this->svc->salvarItens((array) $request->input('itens', []))]);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Família: o dia de um filho. */
    public function filho(Request $request, int $alunoId): JsonResponse
    {
        if ($erro = $this->semAgenda() ?? $this->dataInvalida($request)) {
            return $erro;
        }
        if (!$this->svc->ehFilho($this->usuarioId($request), $alunoId)) {
            return response()->json(['error' => 'Aluno não encontrado.'], 404);
        }
        return response()->json(['data' => $this->svc->diaDoFilho($alunoId, $this->data($request))]);
    }

    // ── Apoio ───────────────────────────────────────────────────────────

    private function editar(Request $request, int $turmaId, callable $acao): JsonResponse
    {
        if ($erro = $this->semAgenda() ?? $this->dataInvalida($request)) {
            return $erro;
        }
        $u = $this->usuarioId($request);
        if (!$this->svc->podeEditar($u, $this->funcoes($request), $turmaId)) {
            return response()->json(['error' => 'Você não preenche a agenda desta turma.'], 403);
        }
        try {
            $d = $acao($u);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $d['pode_editar'] = true;
        return response()->json(['success' => true, 'data' => $d]);
    }

    private function semAgenda(): ?JsonResponse
    {
        return $this->svc->disponivel() ? null : response()->json(['error' => 'A agenda ainda não foi ativada nesta escola.'], 404);
    }

    private function data(Request $request): string
    {
        return (string) ($request->input('data') ?: now()->toDateString());
    }

    private function dataInvalida(Request $request): ?JsonResponse
    {
        $d = $this->data($request);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
            return response()->json(['error' => 'Data inválida.'], 422);
        }
        return $d > now()->toDateString() ? response()->json(['error' => 'A agenda é do dia de hoje ou de dias anteriores.'], 422) : null;
    }

    private function usuarioId(Request $request): int
    {
        return (int) ($request->attributes->get('max_user')->id ?? 0);
    }
}
