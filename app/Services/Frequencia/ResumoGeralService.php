<?php

namespace App\Services\Frequencia;

use Illuminate\Support\Facades\DB;

/**
 * Resumo de frequência por turma, agregando todas as turmas da escola numa
 * query só — diferente dos endpoints de api/frequencia/*.php (que exigem um
 * turma_id por chamada). Existe especificamente pra dar ao Max uma visão
 * agregada sem precisar de N chamadas (uma por turma) dentro do loop de tools,
 * que tem um teto de iterações.
 */
class ResumoGeralService
{
    /**
     * @return array<int, array{turma_id: int, nome_turma: string, percentual_presenca: ?float, total_aulas_periodo: int, abaixo_da_meta: bool}>
     */
    public function porTurma(int $anoLetivo, ?int $mes = null): array
    {
        $sql = '
            SELECT
                t.id AS turma_id,
                t.nome_turma,
                COUNT(f.id) AS total_registros,
                SUM(CASE WHEN f.presente = 1 THEN 1 ELSE 0 END) AS total_presencas,
                COUNT(DISTINCT f.data) AS total_dias_aula
            FROM turmas t
            JOIN matriculas m ON m.turma_id = t.id AND m.ano_letivo = ?
            JOIN frequencia f ON f.aluno_id = m.aluno_id AND YEAR(f.data) = ?
        ';
        $params = [$anoLetivo, $anoLetivo];

        if ($mes) {
            $sql .= ' AND MONTH(f.data) = ?';
            $params[] = $mes;
        }

        $sql .= ' GROUP BY t.id, t.nome_turma ORDER BY t.nome_turma';

        return array_map(function ($row) {
            $total = (int) $row->total_registros;
            $presencas = (int) $row->total_presencas;
            $percentual = $total > 0 ? round(($presencas / $total) * 100, 1) : null;

            return [
                'turma_id'             => (int) $row->turma_id,
                'nome_turma'           => $row->nome_turma,
                'percentual_presenca'  => $percentual,
                'total_aulas_periodo'  => (int) $row->total_dias_aula,
                'abaixo_da_meta'       => $percentual !== null && $percentual < 75,
            ];
        }, DB::select($sql, $params));
    }

    /**
     * Percentual de presença médio da escola inteira, por mês, num intervalo —
     * usado pelo gráfico "Frequência Geral da Escola" da Home. Substitui um
     * padrão anterior de N-meses x N-turmas chamadas individuais por uma única
     * query agregada.
     *
     * @return array<int, array{mes: int, percentual_presenca: ?float}>
     */
    public function porMes(int $anoLetivo, int $mesInicio, int $mesFim): array
    {
        $rows = DB::select('
            SELECT
                MONTH(f.data) AS mes,
                COUNT(f.id) AS total_registros,
                SUM(CASE WHEN f.presente = 1 THEN 1 ELSE 0 END) AS total_presencas
            FROM frequencia f
            JOIN matriculas m ON m.aluno_id = f.aluno_id AND m.ano_letivo = ?
            WHERE YEAR(f.data) = ? AND MONTH(f.data) BETWEEN ? AND ?
            GROUP BY MONTH(f.data)
            ORDER BY mes
        ', [$anoLetivo, $anoLetivo, $mesInicio, $mesFim]);

        $porMes = [];
        foreach ($rows as $row) {
            $total = (int) $row->total_registros;
            $presencas = (int) $row->total_presencas;
            $porMes[(int) $row->mes] = $total > 0 ? round(($presencas / $total) * 100, 1) : null;
        }

        $resultado = [];
        for ($mes = $mesInicio; $mes <= $mesFim; $mes++) {
            $resultado[] = ['mes' => $mes, 'percentual_presenca' => $porMes[$mes] ?? null];
        }

        return $resultado;
    }
}
