<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoAlimentarService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Escola de demonstração: completa o banco até hoje (chamadas, conteúdos,
 * avaliações, notas, BNCC, planejamento, comunicação, financeiro, quadro de
 * horários e rematrícula) com algumas pendências de propósito para os alertas.
 *
 *   php artisan demo:alimentar --confirmar            só completa os dados
 *   php artisan demo:alimentar --confirmar --nomes    e troca as pessoas por nomes fictícios
 *
 * Feito para a homologação: em produção (APP_ENV=production) ele se recusa a rodar.
 */
class DemoAlimentar extends Command
{
    protected $signature = 'demo:alimentar
        {--confirmar : Grava de verdade (sem isto, só mostra o que faria)}
        {--nomes : Troca alunos, famílias e professores por nomes fictícios (CPFs, e-mails, telefones e endereços também)}
        {--ate= : Data limite (AAAA-MM-DD); padrão: hoje}';

    protected $description = 'Completa o banco da escola de demonstração até hoje (nunca em produção)';

    public function handle(DemoAlimentarService $demo): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando altera dados em massa e não roda em produção (APP_ENV=production).');
            return self::FAILURE;
        }
        $ate = $this->option('ate') ? Carbon::parse($this->option('ate')) : now();
        $banco = DB::connection()->getDatabaseName();
        $this->info("Banco: $banco · ambiente: " . app()->environment() . ' · até ' . $ate->format('d/m/Y'));
        if (!$this->option('confirmar')) {
            $this->warn('Nada foi gravado. Rode de novo com --confirmar (faça backup do banco antes).');
            return self::SUCCESS;
        }

        $inicio = microtime(true);
        $demo->executar($ate, (bool) $this->option('nomes'), fn (string $msg) => $this->line("· $msg"));
        $this->info(sprintf('Pronto em %.1f s.', microtime(true) - $inicio));
        return self::SUCCESS;
    }
}
