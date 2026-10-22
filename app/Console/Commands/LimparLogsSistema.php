<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Retenção da auditoria: apaga de logs_sistema o que passou do prazo, em
 * todas as escolas (config('tenants.hosts') + banco padrão do .env).
 *
 * Navegação entre telas é o grosso do volume e perde valor rápido, por isso
 * tem prazo próprio (menor). O resto fica pelo prazo geral.
 *
 * Agendar no cPanel (ex.: uma vez por semana, de madrugada):
 *   php /home2/ande2326/laravel-deploy/artisan logs:limpar
 */
class LimparLogsSistema extends Command
{
    protected $signature = 'logs:limpar
        {--dias=730 : Prazo geral, em dias}
        {--dias-navegacao=180 : Prazo da navegação entre telas, em dias}
        {--simular : Só conta o que seria apagado}';

    protected $description = 'Apaga registros antigos de logs_sistema (auditoria) de todas as escolas';

    public function handle(): int
    {
        $dias = max(30, (int) $this->option('dias'));
        $diasNav = max(7, (int) $this->option('dias-navegacao'));
        $simular = (bool) $this->option('simular');

        $bancoOriginal = config('database.connections.mysql.database');
        $bancos = collect(config('tenants.hosts', []))->values()->push($bancoOriginal)->unique()->filter();

        foreach ($bancos as $dbname) {
            Config::set('database.connections.mysql.database', $dbname);
            DB::purge('mysql');

            try {
                if (!DB::getSchemaBuilder()->hasTable('logs_sistema')) {
                    $this->line("[$dbname] Sem logs_sistema — pulando.");
                    continue;
                }
                $temCategoria = DB::getSchemaBuilder()->hasColumn('logs_sistema', 'categoria');

                $geral = DB::table('logs_sistema')->where('criado_em', '<', now()->subDays($dias));
                $nav = $temCategoria
                    ? DB::table('logs_sistema')->where('categoria', 'navegacao')->where('criado_em', '<', now()->subDays($diasNav))
                    : null;

                if ($simular) {
                    $this->info("[$dbname] Seriam apagados: {$geral->count()} (> $dias dias)" . ($nav ? " + {$nav->count()} de navegação (> $diasNav dias)" : ''));
                    continue;
                }

                $apagados = $this->apagarEmLotes($geral) + ($nav ? $this->apagarEmLotes($nav) : 0);
                $this->info("[$dbname] Apagados: $apagados registro(s).");
            } catch (\Throwable $e) {
                $this->error("[$dbname] Erro: {$e->getMessage()}");
            }
        }

        Config::set('database.connections.mysql.database', $bancoOriginal);
        DB::purge('mysql');

        return self::SUCCESS;
    }

    /** DELETE em lotes para não travar a tabela num servidor compartilhado. */
    private function apagarEmLotes($query): int
    {
        $total = 0;
        do {
            $n = (clone $query)->limit(5000)->delete();
            $total += $n;
        } while ($n > 0);

        return $total;
    }
}
