<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notificacoes\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ativar/desativar avisos no aparelho e mandar um aviso de teste. */
class PushController extends Controller
{
    public function __construct(private PushService $push)
    {
    }

    public function chave(Request $request): JsonResponse
    {
        if (!$this->push->disponivel()) {
            return response()->json(['disponivel' => false]);
        }
        $usuario = $request->attributes->get('max_user');
        return response()->json([
            'disponivel' => true,
            'chave_publica' => $this->push->chaves()['publica'],
            'aparelhos' => $this->push->aparelhos((int) ($usuario->id ?? 0)),
        ]);
    }

    public function inscrever(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');
        $p256dh = (string) $request->input('keys.p256dh');
        $auth = (string) $request->input('keys.auth');
        if (!$this->push->disponivel()) {
            return response()->json(['error' => 'Avisos no celular ainda não estão disponíveis nesta escola.'], 422);
        }
        if (!str_starts_with($endpoint, 'https://') || $p256dh === '' || $auth === '') {
            return response()->json(['error' => 'Inscrição inválida.'], 422);
        }
        $usuario = $request->attributes->get('max_user');
        $this->push->inscrever((int) ($usuario->id ?? 0), $endpoint, $p256dh, $auth, $request->userAgent());
        return response()->json(['success' => true]);
    }

    public function cancelar(Request $request): JsonResponse
    {
        $usuario = $request->attributes->get('max_user');
        if ($this->push->disponivel()) {
            $this->push->cancelar((int) ($usuario->id ?? 0), (string) $request->input('endpoint'));
        }
        return response()->json(['success' => true]);
    }

    /** Aviso de teste para os aparelhos do próprio usuário (enviado na hora, para dar o retorno na tela). */
    public function teste(Request $request): JsonResponse
    {
        $usuario = $request->attributes->get('max_user');
        $primeiro = explode(' ', (string) ($usuario->nome ?? ''))[0];
        try {
            $n = $this->push->enviar([(int) ($usuario->id ?? 0)], 'Avisos ligados ✓',
                "Tudo certo, $primeiro! Você vai receber os avisos da escola neste aparelho.", '/home', 'teste');
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Não foi possível enviar: ' . $e->getMessage()], 422);
        }
        return response()->json(['success' => true, 'entregues' => $n]);
    }
}
