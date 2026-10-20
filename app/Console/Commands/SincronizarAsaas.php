<?php

namespace App\Console\Commands;

use App\Services\Financeiro\Asaas\AsaasConfigService;
use App\Services\Financeiro\Asaas\AsaasSincronizacaoService;
use App\Services\Financeiro\FinanceiroConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Plano B do webhook: consulta no Asaas as cobranças ainda em aberto de cada
 * escola que cobra pelo Asaas e dá as baixas que faltarem.
 *
 * Bancos: os de config('tenants.hosts') + o padrão do .env (mesma regra do
 * whatsapp:notificacoes-diarias). Escola fora do modo Asaas ou sem chave é pulada.
 *
 * Agendar no cPanel (ex.: de hora em hora):
 *   php /home2/ande2326/laravel-deploy/artisan asaas:sincronizar
 */
class SincronizarAsaas extends Command
{
    protected $signature = 'asaas:sincronizar';

    protected $description = 'Consulta no Asaas o status das cobranças em aberto e dá as baixas que faltarem (todas as escolas)';

    public function handle(): int
    {
        $bancoOriginal = config('database.connections.mysql.database');
        $bancos = collect(config('tenants.hosts', []))->values()->push($bancoOriginal)->unique()->filter();

        foreach ($bancos as $dbname) {
            Config::set('database.connections.mysql.database', $dbname);
            DB::purge('mysql');

            try {
                DB::connection()->getPdo();
                if (!DB::getSchemaBuilder()->hasTable('asaas_configuracao')) {
                    $this->line("[$dbname] Sem as tabelas do Asaas (migration não rodada) — pulando.");
                    continue;
                }
                // Serviços resolvidos de novo a cada banco (nada de estado do banco anterior).
                if (!app(FinanceiroConfigService::class)->modoAsaas() || !app(AsaasConfigService::class)->apiKey()) {
                    $this->line("[$dbname] Não cobra pelo Asaas — pulando.");
                    continue;
                }
                $r = app(AsaasSincronizacaoService::class)->sincronizar();
                $this->info("[$dbname] Consultadas: {$r['consultadas']} | Baixas: {$r['baixas']} | Alteradas: {$r['alteradas']} | Erros: {$r['erros']}");
                foreach ($r['detalhes'] as $d) {
                    $this->line("    parcela #{$d['parcela_id']}: {$d['mensagem']}");
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
