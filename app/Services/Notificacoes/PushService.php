<?php

namespace App\Services\Notificacoes;

use App\Services\Documentos\EscolaConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/**
 * Notificações push (Web Push): o aviso aparece na tela do celular ou do
 * computador mesmo com o app fechado. Cada aparelho em que o usuário ativa
 * os avisos vira uma linha em push_inscricoes.
 *
 * As chaves VAPID da escola são geradas sozinhas no primeiro uso. O envio
 * acontece depois da resposta da requisição (não atrasa quem lançou a nota).
 */
class PushService
{
    /** Inscrição que falhou tantas vezes seguidas é descartada. */
    private const MAX_FALHAS = 5;

    private ?bool $tabelas = null;

    /** Comandos agendados (que passam por várias escolas) enviam na hora, não no fim da requisição. */
    public static bool $enviarNaHora = false;

    public function disponivel(): bool
    {
        // Escola que ainda não rodou a migration: o resto do sistema segue normal.
        return $this->tabelas ??= Schema::hasTable('push_inscricoes');
    }

    /** @return array{publica: string, privada: string} */
    public function chaves(): array
    {
        $c = DB::table('push_chaves')->orderBy('id')->first();
        if (!$c) {
            $novas = VAPID::createVapidKeys();
            DB::table('push_chaves')->insert(['chave_publica' => $novas['publicKey'], 'chave_privada' => $novas['privateKey']]);
            $c = DB::table('push_chaves')->orderBy('id')->first();
        }
        return ['publica' => $c->chave_publica, 'privada' => $c->chave_privada];
    }

    /** Liga os avisos neste aparelho para este usuário (aparelho emprestado passa para quem logou). */
    public function inscrever(int $usuarioId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        DB::table('push_inscricoes')->updateOrInsert(
            ['endpoint_hash' => sha1($endpoint)],
            [
                'usuario_id' => $usuarioId, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null, 'falhas' => 0,
            ],
        );
    }

    public function cancelar(int $usuarioId, string $endpoint): void
    {
        DB::table('push_inscricoes')->where('endpoint_hash', sha1($endpoint))->where('usuario_id', $usuarioId)->delete();
    }

    public function aparelhos(int $usuarioId): int
    {
        return $this->disponivel() ? DB::table('push_inscricoes')->where('usuario_id', $usuarioId)->count() : 0;
    }

    /**
     * Agenda o envio para depois da resposta (o usuário que lançou a nota não espera).
     * $url é o caminho dentro do app (ex.: /boletim?aluno_id=3).
     */
    public function avisar(array $usuarioIds, string $titulo, ?string $corpo, ?string $url, ?string $tag = null): void
    {
        $usuarioIds = array_values(array_unique(array_filter(array_map('intval', $usuarioIds))));
        if (!$usuarioIds || !$this->disponivel()) {
            return;
        }
        if (self::$enviarNaHora) {
            try {
                $this->enviar($usuarioIds, $titulo, $corpo, $url, $tag);
            } catch (\Throwable $e) {
                Log::warning('Push: falha no envio', ['erro' => $e->getMessage()]);
            }
            return;
        }
        $banco = config('database.connections.mysql.database');
        app()->terminating(function () use ($usuarioIds, $titulo, $corpo, $url, $tag, $banco) {
            // Multi-escola: garante que o envio roda no banco da escola que gerou o aviso.
            if (config('database.connections.mysql.database') !== $banco) {
                return;
            }
            try {
                $this->enviar($usuarioIds, $titulo, $corpo, $url, $tag);
            } catch (\Throwable $e) {
                Log::warning('Push: falha no envio', ['erro' => $e->getMessage()]);
            }
        });
    }

    /** Envia agora. Devolve quantos aparelhos receberam. */
    public function enviar(array $usuarioIds, string $titulo, ?string $corpo, ?string $url, ?string $tag = null): int
    {
        if (!$this->disponivel()) {
            return 0;
        }
        $inscricoes = DB::table('push_inscricoes')->whereIn('usuario_id', $usuarioIds)->get();
        if ($inscricoes->isEmpty()) {
            return 0;
        }

        $chaves = $this->chaves();
        $email = (string) (app(EscolaConfigService::class)->obter()['email'] ?? '');
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => 'mailto:' . (filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'contato@portalmasterschool.com.br'),
                'publicKey' => $chaves['publica'],
                'privateKey' => $chaves['privada'],
            ],
        ], ['TTL' => 86400, 'urgency' => 'normal', 'batchSize' => 100]);

        $payload = json_encode($this->payload($titulo, $corpo, $url, $tag), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $porEndpoint = [];
        foreach ($inscricoes as $i) {
            $porEndpoint[$i->endpoint] = $i->id;
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $i->endpoint,
                'keys' => ['p256dh' => $i->p256dh, 'auth' => $i->auth],
            ]), $payload);
        }

        $entregues = 0;
        foreach ($webPush->flush() as $r) {
            $id = $porEndpoint[$r->getEndpoint()] ?? null;
            if (!$id) {
                continue;
            }
            if ($r->isSuccess()) {
                $entregues++;
                DB::table('push_inscricoes')->where('id', $id)->update(['ultimo_envio' => now(), 'falhas' => 0]);
            } elseif ($r->isSubscriptionExpired()) {
                // Usuário desinstalou, bloqueou ou limpou os dados do navegador.
                DB::table('push_inscricoes')->where('id', $id)->delete();
            } else {
                DB::table('push_inscricoes')->where('id', $id)->increment('falhas');
                DB::table('push_inscricoes')->where('id', $id)->where('falhas', '>=', self::MAX_FALHAS)->delete();
                Log::info('Push: envio recusado', ['inscricao' => $id, 'motivo' => $r->getReason()]);
            }
        }
        return $entregues;
    }

    /**
     * Formato do service worker do Angular (ngsw): o objeto "notification" é
     * exibido e o toque abre o app na URL do aviso.
     */
    private function payload(string $titulo, ?string $corpo, ?string $url, ?string $tag): array
    {
        $destino = $url ?: '/home';
        return [
            'notification' => array_filter([
                'title' => mb_substr($titulo, 0, 120),
                'body' => $corpo ? mb_substr($corpo, 0, 240) : null,
                'icon' => '/icons/icon-192.png',
                'badge' => '/icons/icon-96.png',
                'tag' => $tag,
                'renotify' => $tag ? true : null,
                'data' => [
                    'url' => $destino,
                    'onActionClick' => [
                        'default' => ['operation' => 'navigateLastFocusedOrOpen', 'url' => $destino],
                    ],
                ],
            ], fn ($v) => $v !== null),
        ];
    }
}
