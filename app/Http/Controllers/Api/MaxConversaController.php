<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Max\ConversaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaxConversaController extends Controller
{
    public function __construct(private ConversaService $conversas)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $userId = (int) ($request->attributes->get('max_user')->id ?? 0);

        return response()->json([
            'data' => $this->conversas->listarDoUsuario($userId),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->attributes->get('max_user')->id ?? 0);

        return response()->json([
            'data' => $this->conversas->obterComMensagens($userId, $id),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->attributes->get('max_user')->id ?? 0);

        $this->conversas->excluir($userId, $id);

        return response()->json(['success' => true]);
    }
}
