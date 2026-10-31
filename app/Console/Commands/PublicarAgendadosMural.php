<?php

namespace App\Console\Commands;

use App\Services\Comunicacao\MuralService;
use App\Services\Notificacoes\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Comunicados agendados do mural: quando a hora chega, o comunicado já aparece
 * sozinho, mas o aviso no celular depende de alguém estar com o app aberto (o
 * pulso da Central chama a mesma rotina). Com esta rotina no cron, o aviso sai
 * na hora mesmo com todo mundo fora do app.
 *
 * Crontab sugerido (a cada 5 minutos):
 *   *\/5 * * * * cd ~/laravel-deploy && php artisan mural:publicar-agendados >> storage/logs/cron.log 2>&1
 */
class PublicarAgendadosMural extends Command
{
    protected $signature = 'mural:publicar-agendados';

    protected $description = 'Envia o aviso no celular dos comunicados agendados cuja hora chegou';

    public function handle(): int
    {
        PushService::$enviarNaHora = true;
        $bancoOriginal = config('database.connections.mysql.database');
        $bancos = collect(config('tenants.hosts', []))->values()->push($bancoOriginal)->unique()->filter();

        foreach ($bancos as $dbname) {
            Config::set('database.connections.mysql.database', $dbname);
            DB::purge('mysql');
            try {
                $n = app()->make(MuralService::class)->avisarAgendados();
                if ($n) {
                    $this->info("[$dbname] $n comunicado(s) agendado(s) publicado(s).");
                }
            } catch (\Throwable $e) {
                $this->error("[$dbname] Erro: {$e->getMessage()}");
            }
        }

        Config::set('database.connections.mysql.database', $bancoOriginal);
        DB::purge('mysql');
        return self::SUCCESS;
    }
}
