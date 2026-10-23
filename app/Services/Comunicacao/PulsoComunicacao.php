<?php

namespace App\Services\Comunicacao;

use Illuminate\Support\Facades\DB;

/**
 * "Pulso" da Central de Comunicação: uma assinatura curta por área que muda
 * sempre que algo muda nela. O front pergunta de tempos em tempos e só
 * recarrega a área cuja assinatura mudou — sem websocket e sem custo de
 * montar listas a cada consulta.
 *
 * A assinatura sai de contagens/máximos das próprias tabelas (funciona em
 * qualquer banco de escola, sem depender de tabela de cache) e fica guardada
 * 3 s na memória do worker do Octane, então muitos usuários online custam
 * poucas consultas por escola.
 *
 * Áreas: mural (posts e reações), leituras (lidos/ciência do mural, só
 * interessa a quem vê as estatísticas), ocorrencias, recados (mensagens e
 * encerramento) e vistos (até onde cada lado leu uma conversa — muda só
 * quando a leitura avança, para dois clientes não ficarem se acordando)
 * e notificacoes (nota/avaliação/falta/boleto novos ou atualizados — a
 * assinatura é da escola toda; o front então recarrega só o resumo).
 */
class PulsoComunicacao
{
    private const TTL = 3;

    /** @var array<string, array{em: float, v: array<string, string>}> por banco */
    private static array $memo = [];

    /** @var array<string, bool> por banco: escola sem a migration de notificações ainda */
    private static array $temNotificacoes = [];

    /** @return array<string, string> */
    public function versoes(): array
    {
        $banco = (string) config('database.connections.mysql.database');
        $m = self::$memo[$banco] ?? null;
        if ($m && microtime(true) - $m['em'] < self::TTL) {
            return $m['v'];
        }

        $linha = DB::selectOne("
            SELECT
              (SELECT CONCAT_WS('.', COUNT(*), MAX(id), UNIX_TIMESTAMP(MAX(atualizado_em))) FROM mural_posts) AS posts,
              (SELECT CONCAT_WS('.', COUNT(*), UNIX_TIMESTAMP(MAX(criado_em))) FROM mural_reacoes) AS reacoes,
              (SELECT CONCAT_WS('.', COUNT(*), UNIX_TIMESTAMP(MAX(ciente_em))) FROM mural_leituras) AS leituras,
              (SELECT CONCAT_WS('.', COUNT(*), MAX(id), UNIX_TIMESTAMP(MAX(atualizado_em))) FROM ocorrencias) AS ocorrencias,
              (SELECT CONCAT_WS('.', COUNT(*), MAX(id)) FROM recados_mensagens) AS mensagens,
              (SELECT CONCAT_WS('.', COUNT(*), SUM(encerrada)) FROM recados_conversas) AS conversas,
              (SELECT CONCAT_WS('.', COUNT(*), SUM(ultima_lida_id)) FROM recados_leituras) AS vistos
        ");

        $temNotif = self::$temNotificacoes[$banco] ??= \Illuminate\Support\Facades\Schema::hasTable('notificacoes');
        $notif = $temNotif
            ? (string) DB::selectOne("SELECT CONCAT_WS('.', COUNT(*), MAX(id), UNIX_TIMESTAMP(MAX(atualizado_em))) AS s FROM notificacoes")->s
            : '';

        $v = [
            'mural' => $this->curta($linha->posts . '|' . $linha->reacoes),
            'leituras' => $this->curta((string) $linha->leituras),
            'ocorrencias' => $this->curta((string) $linha->ocorrencias),
            'recados' => $this->curta($linha->mensagens . '|' . $linha->conversas),
            'vistos' => $this->curta((string) $linha->vistos),
            'notificacoes' => $this->curta($notif),
        ];
        self::$memo[$banco] = ['em' => microtime(true), 'v' => $v];
        return $v;
    }

    private function curta(string $s): string
    {
        return substr(md5($s), 0, 10);
    }
}
