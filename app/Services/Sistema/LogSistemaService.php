<?php

namespace App\Services\Sistema;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Equivalente a registrar_log() em api/helpers/log_helper.php — grava uma
 * entrada de auditoria em logs_sistema (visto na tela "Logs do Sistema").
 *
 * Além do que o chamador informa, preenche sozinho: categoria (pela ação),
 * sessão (claim "sid" do JWT), tela do front (header X-Tela) e navegador.
 * Marca a requisição como auditada, para o middleware AuditarRequisicao não
 * gravar uma segunda linha genérica para a mesma operação.
 */
class LogSistemaService
{
    public const ATRIBUTO_REGISTRADO = 'auditoria_registrada';

    public const ATRIBUTO_SESSAO = 'auditoria_sessao';

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
        ?string $categoria = null,
        ?string $tela = null,
        ?string $criadoEm = null,
    ): void {
        $request->attributes->set(self::ATRIBUTO_REGISTRADO, true);

        $linha = [
            'usuario_id'   => $usuarioId ?: null,
            'username'     => $username !== null ? mb_substr($username, 0, 100) : null,
            'acao'         => mb_substr($acao, 0, 100),
            'endpoint'     => mb_substr($endpoint, 0, 255),
            'metodo'       => mb_substr($metodo, 0, 10),
            'descricao'    => $descricao,
            'ip_address'   => self::ip($request),
            'status_code'  => $statusCode,
            'dados_extras' => $dadosExtras ? json_encode($dadosExtras, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
        ];
        if ($criadoEm) {
            $linha['criado_em'] = $criadoEm;
        }

        $novas = [
            'categoria'  => $categoria ?? AuditoriaCatalogo::categoriaDaAcao($acao),
            'sessao_id'  => self::sessao($request),
            'tela'       => self::limpar($tela ?? $request->header('X-Tela'), 255),
            'user_agent' => self::limpar($request->userAgent(), 255),
        ];

        // Auditoria nunca pode derrubar a operação do usuário. Se a migration
        // 2026_10_22 ainda não rodou neste banco, grava só as colunas antigas.
        try {
            DB::table('logs_sistema')->insert($linha + $novas);
        } catch (\Throwable $e) {
            try {
                DB::table('logs_sistema')->insert($linha);
            } catch (\Throwable $e2) {
                Log::warning('Falha ao gravar logs_sistema: ' . $e2->getMessage(), ['acao' => $acao]);
            }
        }
    }

    public static function ip(Request $request): string
    {
        $ip = $request->header('X-Forwarded-For') ?? $request->ip() ?? 'desconhecido';

        return mb_substr(trim(explode(',', $ip)[0]), 0, 45);
    }

    /** Id da sessão de login: definido no próprio login ou lido do JWT. */
    public static function sessao(Request $request): ?string
    {
        $sid = $request->attributes->get(self::ATRIBUTO_SESSAO)
            ?? ($request->attributes->get('max_user')->sid ?? null);

        return $sid ? mb_substr((string) $sid, 0, 40) : null;
    }

    private static function limpar(?string $valor, int $max): ?string
    {
        $valor = $valor !== null ? trim($valor) : '';

        return $valor === '' ? null : mb_substr($valor, 0, $max);
    }
}
