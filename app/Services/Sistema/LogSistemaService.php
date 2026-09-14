<?php

namespace App\Services\Sistema;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a registrar_log() em api/helpers/log_helper.php — grava uma
 * entrada de auditoria em logs_sistema (visto na tela "Logs do Sistema").
 */
class LogSistemaService
{
    public function registrar(
        Request $request,
        ?int $usuarioId,
        ?string $username,
        string $acao,
        string $endpoint,
        string $metodo,
        ?string $descricao = null,
        ?int $statusCode = null,
        ?array $dadosExtras = null,
    ): void {
        $ip = $request->header('X-Forwarded-For') ?? $request->ip() ?? 'desconhecido';
        $ip = trim(explode(',', $ip)[0]);

        DB::table('logs_sistema')->insert([
            'usuario_id'   => $usuarioId,
            'username'     => $username,
            'acao'         => $acao,
            'endpoint'     => $endpoint,
            'metodo'       => $metodo,
            'descricao'    => $descricao,
            'ip_address'   => $ip,
            'status_code'  => $statusCode,
            'dados_extras' => $dadosExtras ? json_encode($dadosExtras, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }
}
