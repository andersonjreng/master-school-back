<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/admin/get_logs.php. Lado leitura da auditoria — a escrita
 * é feita por App\Services\Sistema\LogSistemaService::registrar().
 */
class LogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas administradores podem acessar os logs.'], 403);
        }

        $pagina = max(1, (int) $request->query('pagina', 1));
        $porPagina = min(100, max(5, (int) $request->query('por_pagina', 20)));
        $offset = ($pagina - 1) * $porPagina;

        $query = DB::table('logs_sistema');

        if ($acao = $request->query('acao')) {
            $query->where('acao', $acao);
        }
        if ($username = $request->query('username')) {
            $query->where('username', 'like', "%$username%");
        }
        if ($dataInicio = $request->query('data_inicio')) {
            $query->where('criado_em', '>=', "$dataInicio 00:00:00");
        }
        if ($dataFim = $request->query('data_fim')) {
            $query->where('criado_em', '<=', "$dataFim 23:59:59");
        }

        $total = (clone $query)->count();

        $logs = $query
            ->orderByDesc('criado_em')
            ->limit($porPagina)->offset($offset)
            ->get(['id', 'usuario_id', 'username', 'acao', 'endpoint', 'metodo', 'descricao', 'ip_address', 'status_code', 'dados_extras', 'criado_em'])
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['dados_extras'] = $row->dados_extras ? json_decode($row->dados_extras, true) : null;

                return $arr;
            });

        return response()->json([
            'data' => $logs,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'paginas' => (int) ceil($total / $porPagina),
        ]);
    }
}
