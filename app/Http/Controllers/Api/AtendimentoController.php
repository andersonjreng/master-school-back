<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Comunicacao\AtendimentoService;
use App\Services\Comunicacao\PerfilComunicacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atendimentos (Comunicação > Atendimentos): a equipe abre horários e a
 * família marca. Regras em AtendimentoService.
 */
class AtendimentoController extends Controller
{
    public function __construct(private AtendimentoService $svc)
    {
    }

    /** Tudo o que a aba precisa: os meus atendimentos, meus horários (equipe) e com quem marcar (família). */
    public function inicio(Request $request): JsonResponse
    {
        if (!$this->svc->disponivel()) {
            return response()->json(['data' => ['indisponivel' => true]]);
        }
        $p = $this->perfil($request);
        return response()->json(['data' => [
            'equipe' => $p->ehEquipe(),
            'familia' => $p->ehFamilia(),
            'atendimentos' => $this->svc->meus($p),
            'janelas' => $p->ehEquipe() ? $this->svc->janelas($p->usuarioId) : [],
            'filhos' => $this->svc->opcoesFamilia($p),
        ]]);
    }

    public function livres(Request $request, int $atendenteId): JsonResponse
    {
        return $this->acao($request, fn () => $this->svc->livresPorDia($atendenteId), false);
    }

    public function marcar(Request $request): JsonResponse
    {
        return $this->acao($request, fn ($p) => $this->svc->marcar($p, $request->all()), false);
    }

    public function cancelar(Request $request, int $id): JsonResponse
    {
        return $this->acao($request, fn ($p) => $this->svc->cancelar($id, $p, $request->input('motivo')), false);
    }

    public function concluir(Request $request, int $id): JsonResponse
    {
        return $this->acao($request, fn ($p) => $this->svc->concluir($id, $p), true);
    }

    public function abrirHorarios(Request $request): JsonResponse
    {
        return $this->acao($request, fn ($p) => $this->svc->abrir($p->usuarioId, $request->all()), true);
    }

    public function fecharHorario(Request $request, int $id): JsonResponse
    {
        return $this->acao($request, fn ($p) => $this->svc->fecharJanela($p->usuarioId, $id), true);
    }

    private function acao(Request $request, callable $fn, bool $soEquipe): JsonResponse
    {
        if (!$this->svc->disponivel()) {
            return response()->json(['error' => 'Os atendimentos ainda não foram ativados nesta escola.'], 404);
        }
        $p = $this->perfil($request);
        if ($soEquipe && !$p->ehEquipe()) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }
        try {
            return response()->json(['success' => true, 'data' => $fn($p)]);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function perfil(Request $r): PerfilComunicacao
    {
        return PerfilComunicacao::de($r->attributes->get('max_user'));
    }
}
