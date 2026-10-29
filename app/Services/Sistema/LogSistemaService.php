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
        $acesso = [
            'origem'      => self::origem($request),
            'dispositivo' => self::dispositivo($request->userAgent()),
        ];

        // Auditoria nunca pode derrubar a operação do usuário. Se as
        // migrations 2026_10_29 / 2026_10_22 ainda não rodaram neste banco,
        // grava só as colunas que existem.
        foreach ([$linha + $novas + $acesso, $linha + $novas, $linha] as $tentativa) {
            try {
                DB::table('logs_sistema')->insert($tentativa);

                return;
            } catch (\Throwable $e) {
                $erro = $e;
            }
        }
        Log::warning('Falha ao gravar logs_sistema: ' . $erro->getMessage(), ['acao' => $acao]);
    }

    /** "app" (instalado no aparelho) ou "web" (navegador), pelo header X-Origem do front. */
    public static function origem(Request $request): ?string
    {
        $origem = strtolower((string) $request->header('X-Origem'));

        return in_array($origem, ['app', 'web'], true) ? $origem : null;
    }

    /** celular, tablet ou computador, pelo navegador (a migration 2026_10_29 usa a mesma regra). */
    public static function dispositivo(?string $userAgent): ?string
    {
        if (!$userAgent) {
            return null;
        }
        if (str_contains($userAgent, 'iPad') || (str_contains($userAgent, 'Android') && !str_contains($userAgent, 'Mobile'))) {
            return 'tablet';
        }
        if (str_contains($userAgent, 'Mobi') || str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'Android')) {
            return 'celular';
        }

        return 'computador';
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
