<?php

namespace App\Console\Commands;

use App\Models\InteressePai;
use Illuminate\Console\Command;

class ResumoInteressePais extends Command
{
    protected $signature = 'interesse:resumo {escola? : nome da escola para detalhar (acento e maiúscula não importam)}';
    protected $description = 'Mostra quantas famílias pediram o MasterSchool por escola';

    public function handle(): int
    {
        if ($escola = $this->argument('escola')) {
            $q = InteressePai::apresentaveis()->daEscola($escola);
            $total = (clone $q)->count();
            if ($total === 0) {
                $this->warn('Nenhum pedido apresentável para essa escola.');
                return self::SUCCESS;
            }
            $this->info("{$total} famílias · " . (clone $q)->where('lider', true)->count() . ' topam ir à direção');

            $contagem = fn (string $campo) => (clone $q)->pluck($campo)->flatten()->filter()->countBy()->sortDesc();
            $this->line("\nO que mais querem:");
            foreach ($contagem('interesses') as $k => $v) $this->line("  {$v}× {$k}");
            $this->line("\nComo a escola se comunica hoje:");
            foreach ((clone $q)->pluck('comunicacao_atual')->countBy()->sortDesc() as $k => $v) $this->line("  {$v}× {$k}");

            $this->line("\nRecados:");
            foreach ((clone $q)->whereNotNull('comentario')->pluck('comentario') as $c) $this->line("  “{$c}”");
            return self::SUCCESS;
        }

        $linhas = InteressePai::apresentaveis()
            ->selectRaw('MAX(escola_nome) as escola_nome, COUNT(*) as familias, SUM(lider) as lideres, MAX(created_at) as ultimo')
            ->groupBy('escola_slug')->orderByDesc('familias')->get()
            ->map(fn ($r) => [$r->escola_nome, $r->familias, (int) $r->lideres, $r->ultimo]);

        $this->table(['Escola', 'Famílias', 'Líderes', 'Último pedido'], $linhas);
        return self::SUCCESS;
    }
}
