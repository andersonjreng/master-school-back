<?php

namespace App\Services\Frequencia;

use Illuminate\Support\Facades\DB;

/**
 * Monta o relatório analítico de frequência de uma turma (tela
 * /frequencia/relatorio) num único payload: indicadores, série temporal,
 * comparação com a escola, ranking de alunos, sequências de faltas, mapa de
 * presença, dias da semana e motivos das faltas justificadas.
 *
 * A turma do aluno vem de `matriculas` (histórico), limitada ao período da
 * matrícula (data_inicio/data_fim) — `frequencia` não guarda turma, então sem
 * esse corte um aluno transferido no meio do ano teria a frequência do ano
 * inteiro contada nas duas turmas.
 */
class RelatorioFrequenciaService
{
    /** Mínimo legal de frequência (LDB, art. 24, VI). */
    public const META = 75.0;

    private const DIAS_SEMANA = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    public function gerar(int $turmaId, int $ano, ?int $mes, ?int $alunoId): array
    {
        $registros = $this->registrosDaTurma($turmaId, $ano, $mes, $alunoId);

        $alunos = $this->porAluno($registros);
        $resumo = $this->resumo($registros, $alunos);
        $resumo['percentual_anterior'] = $mes ? $this->percentualMesAnterior($turmaId, $ano, $mes, $alunoId) : null;
        $resumo['media_escola'] = $this->percentualEscola($ano, $mes);
        $resumo['percentual_ano'] = $mes ? $this->percentualDoAno($turmaId, $ano, $alunoId) : $resumo['percentual'];

        return [
            'periodo'       => ['tipo' => $mes ? 'mensal' : 'anual', 'ano' => $ano, 'mes' => $mes],
            'meta'          => self::META,
            'resumo'        => $resumo,
            'serie'         => $this->serie($registros, $mes !== null),
            'serie_escola'  => $this->serieEscola($ano, $mes),
            'dias_semana'   => $this->diasSemana($registros),
            'justificativas' => $this->justificativas($registros),
            'alunos'        => $alunos,
            'matriz'        => $mes ? $this->matrizDiaria($registros, $alunos) : $this->matrizMensal($registros, $alunos),
        ];
    }

    private function registrosDaTurma(int $turmaId, int $ano, ?int $mes, ?int $alunoId): array
    {
        $sql = "
            SELECT f.aluno_id, u.nome_completo AS nome, f.data, f.presente, f.justificativa
            FROM frequencia f
            JOIN matriculas m
                ON m.aluno_id = f.aluno_id
               AND m.turma_id = ?
               AND m.ano_letivo = ?
               AND f.data >= COALESCE(m.data_inicio, '1000-01-01')
               AND f.data <= COALESCE(m.data_fim, '9999-12-31')
            JOIN alunos a ON a.id = f.aluno_id
            JOIN usuarios u ON u.id = a.usuario_id
            WHERE YEAR(f.data) = ?
        ";
        $params = [$turmaId, $ano, $ano];

        if ($mes) {
            $sql .= ' AND MONTH(f.data) = ?';
            $params[] = $mes;
        }
        if ($alunoId) {
            $sql .= ' AND f.aluno_id = ?';
            $params[] = $alunoId;
        }
        // DISTINCT na prática: o mesmo aluno pode ter 2 matrículas sobrepostas
        // na mesma turma (ex.: backfill + sistema); agrupa por aluno+data.
        $sql .= ' GROUP BY f.aluno_id, u.nome_completo, f.data, f.presente, f.justificativa ORDER BY f.data ASC, u.nome_completo ASC';

        return array_map(fn ($r) => [
            'aluno_id'     => (int) $r->aluno_id,
            'nome'         => $r->nome,
            'data'         => $r->data,
            'presente'     => (bool) $r->presente,
            'justificada'  => !$r->presente && trim((string) $r->justificativa) !== '',
            'justificativa' => $r->justificativa !== null ? trim($r->justificativa) : null,
        ], DB::select($sql, $params));
    }

    private function porAluno(array $registros): array
    {
        $porAluno = [];
        foreach ($registros as $r) {
            $id = $r['aluno_id'];
            $porAluno[$id] ??= [
                'aluno_id' => $id, 'nome' => $r['nome'], 'presencas' => 0, 'faltas' => 0,
                'justificadas' => 0, 'maior_sequencia_faltas' => 0, 'sequencia_atual_faltas' => 0,
                'ultima_falta' => null, '_seq' => 0,
            ];
            $a = &$porAluno[$id];
            if ($r['presente']) {
                $a['presencas']++;
                $a['_seq'] = 0;
            } else {
                $a['faltas']++;
                if ($r['justificada']) {
                    $a['justificadas']++;
                }
                $a['_seq']++;
                $a['maior_sequencia_faltas'] = max($a['maior_sequencia_faltas'], $a['_seq']);
                $a['ultima_falta'] = $r['data'];
            }
            unset($a);
        }

        $lista = [];
        foreach ($porAluno as $a) {
            $total = $a['presencas'] + $a['faltas'];
            $a['sequencia_atual_faltas'] = $a['_seq'];
            unset($a['_seq']);
            $a['total'] = $total;
            $a['percentual'] = $total > 0 ? round($a['presencas'] / $total * 100, 1) : 0.0;
            $lista[] = $a;
        }

        // Ranking: maior frequência primeiro; empate desempata por menos faltas e nome.
        usort($lista, fn ($x, $y) => [$y['percentual'], $x['faltas'], $x['nome']] <=> [$x['percentual'], $y['faltas'], $y['nome']]);
        foreach ($lista as $i => &$a) {
            $a['posicao'] = $i + 1;
        }
        unset($a);

        return $lista;
    }

    private function resumo(array $registros, array $alunos): array
    {
        $presencas = count(array_filter($registros, fn ($r) => $r['presente']));
        $faltas = count($registros) - $presencas;
        $justificadas = count(array_filter($registros, fn ($r) => $r['justificada']));
        $dias = count(array_unique(array_column($registros, 'data')));

        return [
            'percentual'          => count($registros) > 0 ? round($presencas / count($registros) * 100, 1) : 0.0,
            'presencas'           => $presencas,
            'faltas'              => $faltas,
            'faltas_justificadas' => $justificadas,
            'dias_letivos'        => $dias,
            'total_alunos'        => count($alunos),
            'alunos_em_risco'     => count(array_filter($alunos, fn ($a) => $a['percentual'] < self::META)),
            'alunos_com_sequencia' => count(array_filter($alunos, fn ($a) => $a['sequencia_atual_faltas'] >= 3)),
        ];
    }

    /** Mensal → um ponto por dia letivo; anual → um ponto por mês. */
    private function serie(array $registros, bool $diaria): array
    {
        $grupos = [];
        foreach ($registros as $r) {
            $chave = $diaria ? $r['data'] : substr($r['data'], 0, 7);
            $grupos[$chave] ??= ['chave' => $chave, 'presencas' => 0, 'faltas' => 0];
            $r['presente'] ? $grupos[$chave]['presencas']++ : $grupos[$chave]['faltas']++;
        }
        ksort($grupos);

        return array_values(array_map(function ($g) {
            $total = $g['presencas'] + $g['faltas'];
            $g['percentual'] = $total > 0 ? round($g['presencas'] / $total * 100, 1) : 0.0;
            return $g;
        }, $grupos));
    }

    private function diasSemana(array $registros): array
    {
        $grupos = [];
        foreach ($registros as $r) {
            $dow = (int) date('N', strtotime($r['data']));
            $grupos[$dow] ??= ['dia' => $dow, 'label' => self::DIAS_SEMANA[$dow], 'presencas' => 0, 'faltas' => 0];
            $r['presente'] ? $grupos[$dow]['presencas']++ : $grupos[$dow]['faltas']++;
        }
        ksort($grupos);

        return array_values(array_map(function ($g) {
            $total = $g['presencas'] + $g['faltas'];
            $g['percentual'] = $total > 0 ? round($g['presencas'] / $total * 100, 1) : 0.0;
            return $g;
        }, $grupos));
    }

    private function justificativas(array $registros): array
    {
        $contagem = [];
        foreach ($registros as $r) {
            if ($r['justificada']) {
                $contagem[$r['justificativa']] = ($contagem[$r['justificativa']] ?? 0) + 1;
            }
        }
        arsort($contagem);

        // Até 6 motivos; o resto vira "Outros" para o gráfico não virar uma lista.
        $top = array_slice($contagem, 0, 6, true);
        $outros = array_sum(array_slice($contagem, 6, null, true));
        $lista = [];
        foreach ($top as $motivo => $total) {
            $lista[] = ['motivo' => $motivo, 'total' => $total];
        }
        if ($outros > 0) {
            $lista[] = ['motivo' => 'Outros', 'total' => $outros];
        }

        return $lista;
    }

    /** Aluno × dia letivo do mês: 'P' presente, 'F' falta, 'J' falta justificada, null sem lançamento. */
    private function matrizDiaria(array $registros, array $alunos): array
    {
        $datas = array_values(array_unique(array_column($registros, 'data')));
        sort($datas);
        $indice = array_flip($datas);

        $linhas = [];
        foreach ($alunos as $a) {
            $linhas[$a['aluno_id']] = ['aluno_id' => $a['aluno_id'], 'nome' => $a['nome'], 'valores' => array_fill(0, count($datas), null)];
        }
        foreach ($registros as $r) {
            $linhas[$r['aluno_id']]['valores'][$indice[$r['data']]] = $r['presente'] ? 'P' : ($r['justificada'] ? 'J' : 'F');
        }

        usort($linhas, fn ($x, $y) => strcmp($x['nome'], $y['nome']));

        return ['tipo' => 'diaria', 'colunas' => $datas, 'linhas' => $linhas];
    }

    /** Aluno × mês do ano: percentual de presença do aluno no mês (null sem lançamento). */
    private function matrizMensal(array $registros, array $alunos): array
    {
        $meses = array_values(array_unique(array_map(fn ($r) => substr($r['data'], 0, 7), $registros)));
        sort($meses);
        $indice = array_flip($meses);

        $acc = [];
        foreach ($registros as $r) {
            $k = $indice[substr($r['data'], 0, 7)];
            $acc[$r['aluno_id']][$k][0] = ($acc[$r['aluno_id']][$k][0] ?? 0) + ($r['presente'] ? 1 : 0);
            $acc[$r['aluno_id']][$k][1] = ($acc[$r['aluno_id']][$k][1] ?? 0) + 1;
        }

        $linhas = [];
        foreach ($alunos as $a) {
            $valores = array_fill(0, count($meses), null);
            foreach ($acc[$a['aluno_id']] ?? [] as $k => [$p, $t]) {
                $valores[$k] = round($p / $t * 100, 1);
            }
            $linhas[] = ['aluno_id' => $a['aluno_id'], 'nome' => $a['nome'], 'valores' => $valores];
        }
        usort($linhas, fn ($x, $y) => strcmp($x['nome'], $y['nome']));

        return ['tipo' => 'mensal', 'colunas' => $meses, 'linhas' => $linhas];
    }

    private function percentualMesAnterior(int $turmaId, int $ano, int $mes, ?int $alunoId): ?float
    {
        if ($mes <= 1) {
            return null;
        }
        $registros = $this->registrosDaTurma($turmaId, $ano, $mes - 1, $alunoId);
        if (!$registros) {
            return null;
        }
        $presencas = count(array_filter($registros, fn ($r) => $r['presente']));

        return round($presencas / count($registros) * 100, 1);
    }

    /** Acumulado do ano inteiro — exibido junto do mensal como contexto. */
    private function percentualDoAno(int $turmaId, int $ano, ?int $alunoId): ?float
    {
        $registros = $this->registrosDaTurma($turmaId, $ano, null, $alunoId);
        if (!$registros) {
            return null;
        }
        $presencas = count(array_filter($registros, fn ($r) => $r['presente']));

        return round($presencas / count($registros) * 100, 1);
    }

    /** Percentual de presença da escola inteira no mesmo período (referência). */
    private function percentualEscola(int $ano, ?int $mes): ?float
    {
        $sql = 'SELECT SUM(f.presente = 1) AS presencas, COUNT(*) AS total FROM frequencia f WHERE YEAR(f.data) = ?';
        $params = [$ano];
        if ($mes) {
            $sql .= ' AND MONTH(f.data) = ?';
            $params[] = $mes;
        }
        $row = DB::selectOne($sql, $params);

        return (int) $row->total > 0 ? round($row->presencas / $row->total * 100, 1) : null;
    }

    /** Mesma granularidade de serie(): por dia no mês, por mês no ano. */
    private function serieEscola(int $ano, ?int $mes): array
    {
        $chave = $mes ? 'f.data' : "DATE_FORMAT(f.data, '%Y-%m')";
        $sql = "SELECT $chave AS chave, SUM(f.presente = 1) AS presencas, COUNT(*) AS total
                FROM frequencia f WHERE YEAR(f.data) = ?";
        $params = [$ano];
        if ($mes) {
            $sql .= ' AND MONTH(f.data) = ?';
            $params[] = $mes;
        }
        $sql .= ' GROUP BY chave ORDER BY chave';

        return array_map(fn ($r) => [
            'chave'      => $r->chave,
            'percentual' => round($r->presencas / $r->total * 100, 1),
        ], DB::select($sql, $params));
    }
}
