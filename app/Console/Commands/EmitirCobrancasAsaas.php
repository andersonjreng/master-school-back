<?php

namespace App\Console\Commands;

use App\Services\Financeiro\Asaas\AsaasCobrancaService;
use App\Services\Financeiro\Asaas\AsaasConfigService;
use App\Services\Financeiro\Asaas\AsaasRotinasService;
use App\Services\Financeiro\FinanceiroConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Emissão automática: em cada escola que cobra pelo Asaas e ligou "Emitir
 * automaticamente" (Parametrizações → Financeiro), emite as cobranças das
 * parcelas que vencem nos próximos N dias.
 *
 * Bancos: os de config('tenants.hosts') + o padrão do .env (mesma regra do
 * asaas:sincronizar). Rodar uma vez por dia — no cPanel, por exemplo às 7h:
 *   php /home2/ande2326/laravel-deploy/artisan asaas:emitir
 */
class EmitirCobrancasAsaas extends Command
{
    protected $signature = 'asaas:emitir';

    protected $description = 'Emite no Asaas as cobranças das parcelas que vencem nos próximos N dias (escolas com emissão automática ligada)';

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
                $config = app(FinanceiroConfigService::class)->todas();
                if ($config['modo_cobranca'] !== 'asaas' || !app(AsaasConfigService::class)->apiKey()) {
                    $this->line("[$dbname] Não cobra pelo Asaas — pulando.");
                    continue;
                }
                if (!$config['asaas_emissao_automatica']) {
                    $this->line("[$dbname] Emissão automática desligada — pulando.");
                    continue;
                }

                $r = app(AsaasCobrancaService::class)->emitirAutomaticamente();
                $resumo = "{$r['emitidas']} emitida(s)"
                    . ($r['falhas'] ? ", {$r['falhas']} com erro" : '')
                    . ($r['pendencias'] ? ", {$r['pendencias']} com cadastro incompleto" : '')
                    . ($r['vencidas'] ? ", {$r['vencidas']} vencida(s) aguardando emissão manual" : '');
                app(AsaasRotinasService::class)->registrar('emissao', $resumo, array_values(array_filter($r['resultado'], fn ($x) => !$x['ok'])));
                $this->info("[$dbname] $resumo");
            } catch (\Throwable $e) {
                $this->error("[$dbname] Erro: {$e->getMessage()}");
            }
        }

        Config::set('database.connections.mysql.database', $bancoOriginal);
        DB::purge('mysql');

        return self::SUCCESS;
    }
}
