<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Max\ConversaService;
use App\Services\Max\MaxAgentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MaxAgentController extends Controller
{
    public function __construct(
        private MaxAgentService $agent,
        private ConversaService $conversas,
    ) {
    }

    /**
     * Dois modos, pelo mesmo endpoint:
     * - Corpo com `historico`: modo efêmero (widget flutuante) — histórico é gerenciado
     *   pelo client, nada é persistido. Mantém o contrato que o widget já usa hoje.
     * - Corpo com `conversa_id` (ou nenhum dos dois): modo persistido (tela dedicada) —
     *   histórico e mensagens vêm/vão pro banco via ConversaService.
     */
    public function chat(Request $request): JsonResponse
    {
        $pergunta = trim((string) $request->input('pergunta', ''));
        $token = (string) $request->attributes->get('max_jwt_token', '');
        $jwtUser = $request->attributes->get('max_user');

        if ($pergunta === '') {
            return response()->json(['erro' => 'Pergunta não informada']);
        }

        if ($request->has('historico')) {
            $historico = (array) $request->input('historico', []);
            $resultado = $this->agent->responder($pergunta, $historico, $token, $jwtUser);
        } else {
            $conversaId = $request->input('conversa_id');
            $conversaId = $conversaId !== null ? (int) $conversaId : null;
            $resultado = $this->conversas->responderNaConversa($jwtUser, $pergunta, $conversaId, $token);
        }

        return response()->json($resultado['body'], $resultado['status']);
    }
}
