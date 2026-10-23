<?php

namespace App\Console\Commands;

use App\Services\Notificacoes\NotificacaoService;
use App\Services\Notificacoes\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Avisos que dependem da data (boleto vencendo/vencido, avaliação amanhã) só
 * nascem quando o usuário abre o sino. Esta rotina, de manhã, roda a mesma
 * conta para quem ativou o push — e o aviso chega no celular sem abrir o app.
 * Passa por todas as escolas (config('tenants.hosts') + banco do .env).
 *
 * Crontab sugerido (todo dia às 7h30):
 *   30 7 * * * cd ~/laravel-deploy && php artisan push:avisos-diarios >> storage/logs/cron.log 2>&1
 */
class EnviarPushDiario extends Command
{
    protected $signature = 'push:avisos-diarios';

    protected $description = 'Gera os avisos do dia (boletos, avaliações de amanhã) e envia por push para quem ativou';

    public function handle(): int
    {
        PushService::$enviarNaHora = true;
        $bancoOriginal = config('database.connections.mysql.database');
        $bancos = collect(config('tenants.hosts', []))->values()->push($bancoOriginal)->unique()->filter();

        foreach ($bancos as $dbname) {
            Config::set('database.connections.mysql.database', $dbname);
            DB::purge('mysql');

            try {
                if (!DB::getSchemaBuilder()->hasTable('push_inscricoes')) {
                    $this->line("[$dbname] Sem push_inscricoes — pulando.");
                    continue;
                }
                $usuarios = DB::table('push_inscricoes')->distinct()->pluck('usuario_id');
                $notificacoes = app(NotificacaoService::class);
                foreach ($usuarios as $uid) {
                    $funcoes = DB::table('usuario_funcao as uf')->join('funcoes as f', 'f.id', '=', 'uf.funcao_id')
                        ->where('uf.usuario_id', $uid)->pluck('f.nome_funcao')->all();
                    $notificacoes->sincronizar((int) $uid, $funcoes);
                }
                $this->info("[$dbname] Avisos do dia conferidos para {$usuarios->count()} usuário(s) com push.");
            } catch (\Throwable $e) {
                $this->error("[$dbname] Erro: {$e->getMessage()}");
            }
        }

        Config::set('database.connections.mysql.database', $bancoOriginal);
        DB::purge('mysql');
        return self::SUCCESS;
    }
}
