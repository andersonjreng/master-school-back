<?php

namespace App\Services\Turmas;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bimestre atual de um ano letivo (pelas datas cadastradas em
 * Parametrizações → Ano Letivo) e progresso em semanas. Usado pelos painéis
 * de professor e responsável.
 */
class PeriodoLetivoService
{
    public function periodo(int $ano, Carbon $hoje): array
    {
        $unidades = $this->unidadesComDatas($ano);

        $inicioAno = $unidades->min('data_inicio');
        $atual = $unidades->first(fn ($u) => $hoje->between(Carbon::parse($u->data_inicio), Carbon::parse($u->data_fim)));
        $situacao = 'em_andamento';
        if (!$atual) {
            $atual = $unidades->first(fn ($u) => Carbon::parse($u->data_inicio)->gt($hoje));
            $situacao = $atual ? 'antes' : 'sem_datas';
        }
        if (!$atual) {
            return ['bimestre' => null, 'unidade_id' => null, 'situacao' => 'sem_datas', 'inicio_ano' => $inicioAno];
        }

        $ini = Carbon::parse($atual->data_inicio);
        $fim = Carbon::parse($atual->data_fim);
        $semanas = max(1, (int) ceil(($this->dias($ini, $fim) + 1) / 7));
        $semana = $situacao === 'em_andamento' ? min($semanas, intdiv($this->dias($ini, $hoje), 7) + 1) : 0;
        $total = max(1, $this->dias($ini, $fim));
        $progresso = $situacao === 'em_andamento' ? round(min(1, $this->dias($ini, $hoje) / $total) * 100) : 0;

        return [
            'bimestre'       => $atual->nome_unidade,
            'unidade_id'     => (int) $atual->id,
            'situacao'       => $situacao,
            'inicio'         => $ini->toDateString(),
            'fim'            => $fim->toDateString(),
            'semana'         => $semana,
            'semanas'        => $semanas,
            'progresso'      => $progresso,
            'dias_restantes' => $situacao === 'em_andamento' ? $this->dias($hoje, $fim) : $this->dias($hoje, $ini),
            'inicio_ano'     => $inicioAno,
        ];
    }

    /** Bimestres do ano que têm datas válidas (datas zeradas do legado são ignoradas). */
    public function unidadesComDatas(int $ano)
    {
        return DB::table('unidades_letivas')->where('ano_letivo', $ano)->orderBy('id')
            ->get(['id', 'nome_unidade', 'data_inicio', 'data_fim'])
            ->filter(fn ($u) => $u->data_inicio && $u->data_inicio !== '0000-00-00' && $u->data_fim && $u->data_fim !== '0000-00-00')
            ->values();
    }

    /** Dias inteiros entre duas datas (Carbon 3 devolve diferença com sinal e decimal). */
    public function dias(Carbon $a, Carbon $b): int
    {
        return (int) abs(round($a->diffInDays($b)));
    }
}
