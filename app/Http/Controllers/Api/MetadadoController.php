<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/admin/{get_anos_letivos,get_disciplinas,get_sistemas_avaliacao}.php.
 * Endpoints de leitura de metadados/catálogo, sem restrição de papel (qualquer
 * usuário autenticado pode consultar), igual ao legado.
 */
class MetadadoController extends Controller
{
    public function anosLetivos(): JsonResponse
    {
        $anos = DB::table('anos_letivos')->orderByDesc('ano')->pluck('ano')->map(fn ($a) => (int) $a);

        return response()->json(['success' => true, 'ano_letivo' => $anos]);
    }

    public function disciplinas(): JsonResponse
    {
        $disciplinas = DB::table('disciplinas')->orderBy('nome_disciplina')->get(['id', 'nome_disciplina', 'descricao']);

        return response()->json(['success' => true, 'disciplinas' => $disciplinas]);
    }

    public function sistemasAvaliacao(): JsonResponse
    {
        $sistemas = DB::table('sistemas_avaliacao')->orderBy('id')->get(['id', 'nome_sistema', 'media_minima', 'descricao']);

        return response()->json(['success' => true, 'sistemas_avaliacao' => $sistemas]);
    }
}
