<?php

namespace App\Services\Avaliacoes;

use Illuminate\Support\Facades\DB;

/**
 * Regra única da média bimestral (a mesma do boletim):
 *
 *   média = Σ (nota ÷ valor_máximo × peso) ÷ 10   — escala 0 a 10
 *
 * só com as avaliações que têm nota lançada. Os pesos do modelo de avaliação
 * somam 100, então a média completa vai de 0 a 10; enquanto nem todo o peso
 * foi avaliado, ela é parcial (ver pesoAvaliado).
 *
 * Média mínima: a do sistema de avaliação da turma (sistemas_avaliacao),
 * ex.: 6,0 no "Numérico Simples" e 7,0 no "Numérico Rigoroso".
 */
class MediaBimestralService
{
    public const MEDIA_MINIMA_PADRAO = 6.0;

    /**
     * @param iterable<array{nota: ?float, valor_maximo: float, peso: float|int}> $avaliacoes
     */
    public function media(iterable $avaliacoes): ?float
    {
        $soma = 0.0;
        $temNota = false;
        foreach ($avaliacoes as $av) {
            if ($av['nota'] !== null && (float) $av['valor_maximo'] > 0) {
                $soma += ((float) $av['nota'] / (float) $av['valor_maximo']) * (float) $av['peso'];
                $temNota = true;
            }
        }
        return $temNota ? round($soma / 10, 2) : null;
    }

    /** Soma dos pesos das avaliações com nota (100 = bimestre todo avaliado). */
    public function pesoAvaliado(iterable $avaliacoes): float
    {
        $peso = 0.0;
        foreach ($avaliacoes as $av) {
            if ($av['nota'] !== null) {
                $peso += (float) $av['peso'];
            }
        }
        return $peso;
    }

    public function mediaMinima(int $turmaId): float
    {
        $minima = DB::table('turmas as t')
            ->leftJoin('sistemas_avaliacao as s', 's.id', '=', 't.sistema_avaliacao_id')
            ->where('t.id', $turmaId)
            ->value('s.media_minima');
        return $minima !== null && (float) $minima > 0 ? (float) $minima : self::MEDIA_MINIMA_PADRAO;
    }
}
