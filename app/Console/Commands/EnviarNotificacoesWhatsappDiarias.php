<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\WhatsappNotificacaoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/whatsapp/enviar_notificacoes_diarias.php.
 *
 * Notifica responsáveis via WhatsApp sobre (1) notas lançadas/alteradas no
 * dia e (2) avaliações agendadas/criadas no dia — pra cada escola (tenant)
 * configurada, não só um banco fixo como o legado (que tinha
 * name_default + name_colegio_batista hardcoded em config.php).
 *
 * Bancos processados: todo banco distinto em config('tenants.hosts')
 * (config/tenants.php) + o banco padrão do .env (DB_DATABASE) — cobre tanto
 * produção (múltiplas escolas por subdomínio) quanto o ambiente local (só
 * o banco de teste, já que tenants.hosts fica vazio sem as variáveis
 * TENANT_*_DB no .env local).
 */
class EnviarNotificacoesWhatsappDiarias extends Command
{
    protected $signature = 'whatsapp:notificacoes-diarias';

    protected $description = 'Notifica responsáveis via WhatsApp sobre notas lançadas e avaliações agendadas hoje, em todas as escolas configuradas';

    public function handle(WhatsappNotificacaoService $whatsapp): int
    {
        if (!config('whatsapp.token')) {
            $this->error('WHATSAPP_TOKEN não configurado no .env. Abortando.');

            return self::FAILURE;
        }

        $bancoOriginal = config('database.connections.mysql.database');
        $bancos = collect(config('tenants.hosts', []))
            ->values()
            ->push($bancoOriginal)
            ->unique()
            ->filter();

        foreach ($bancos as $dbname) {
            Config::set('database.connections.mysql.database', $dbname);
            DB::purge('mysql');

            try {
                DB::connection()->getPdo();
            } catch (\Throwable $e) {
                $this->error("[$dbname] Falha ao conectar: {$e->getMessage()}");
                continue;
            }

            try {
                $resultado = $whatsapp->processarNotificacoesNotas();
                $this->info("[$dbname] Notas — Enviados: {$resultado['enviados']} | Já notificados antes: {$resultado['ignorados']} | Falhas: {$resultado['falhas']}");
            } catch (\Throwable $e) {
                $this->error("[$dbname] Erro ao processar notificações de notas: {$e->getMessage()}");
            }

            try {
                $resultadoAvaliacoes = $whatsapp->processarAvisosAvaliacaoAgendada();
                $this->info("[$dbname] Avaliações agendadas — Enviados: {$resultadoAvaliacoes['enviados']} | Já notificados antes: {$resultadoAvaliacoes['ignorados']} | Falhas: {$resultadoAvaliacoes['falhas']}");
            } catch (\Throwable $e) {
                $this->error("[$dbname] Erro ao processar avisos de avaliação agendada: {$e->getMessage()}");
            }
        }

        // Restaura o banco original — importante em contexto de worker de
        // longa duração (Octane), onde o processo continua vivo pra atender
        // requisições HTTP depois do comando terminar.
        Config::set('database.connections.mysql.database', $bancoOriginal);
        DB::purge('mysql');

        return self::SUCCESS;
    }
}
