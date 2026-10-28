<?php

namespace App\Services\Turmas;

use App\Services\Frequencia\RelatorioFrequenciaService;
use Illuminate\Support\Facades\DB;

/**
 * Revisão do Max no encerramento do ano: os alunos que merecem um segundo
 * olhar antes de fechar a turma, cada um com a decisão sugerida e o porquê.
 * Determinístico (regras, não IA); quem decide é a escola.
 *
 * - Frequência abaixo de 75% no Fundamental: a LDB (art. 24, VI) exige 75%
 *   para aprovar, mesmo com as médias em dia.
 * - Retido por pouco: uma só disciplina abaixo, a menos de 1 ponto da mínima
 *   (caso típico de conselho de classe).
 * - Notas faltando: disciplinas da turma sem nota no ano; a sugestão pelas
 *   médias pode estar errada.
 * - Educação Infantil (turma com etapa da BNCC) não retém (LDB art. 31).
 */
class RevisaoEncerramentoService
{
    /** @param array $painel resultado de ViradaAnoService::painel() */
    public function revisar(array $painel): array
    {
        $turmaId = $painel['turma']['id'];
        $ano = $painel['turma']['ano_letivo'];
        $infantil = DB::table('turmas')->where('id', $turmaId)->value('etapa_bncc') !== null;
        $medias = app(\App\Services\Documentos\DocumentosEscolaresService::class)->mediasDaTurma($turmaId);
        $minima = (float) $medias['media_minima'];
        $nomesDisc = collect($medias['disciplinas'])->pluck('nome', 'id')->all();
        $daTurma = DB::table('turma_professor_disciplina as v')->join('disciplinas as d', 'd.id', '=', 'v.disciplina_id')
            ->where('v.turma_id', $turmaId)->pluck('d.nome_disciplina', 'v.disciplina_id')->all();
        $alunos = array_column($painel['alunos'], 'aluno_id');
        $freq = DB::table('frequencia')->whereIn('aluno_id', $alunos ?: [0])->whereYear('data', $ano)
            ->selectRaw('aluno_id, COUNT(*) AS dias, SUM(presente = 1) AS presencas')->groupBy('aluno_id')->get()->keyBy('aluno_id');

        $casos = [];
        $semNotas = [];
        $porPouco = [];
        foreach ($painel['alunos'] as $a) {
            $id = $a['aluno_id'];
            $primeiro = explode(' ', $a['nome'])[0];
            $temVaga = in_array($a['rematricula']['status'] ?? null, ['confirmada', 'aguardando_pagamento'], true);
            $n = $medias['alunos'][$id] ?? null;
            $f = $freq[$id] ?? null;
            $pct = $f && $f->dias ? round($f->presencas / $f->dias * 100, 1) : null;

            if ($infantil) {
                if ($a['resultado_sugerido'] === 'retido' && $temVaga) {
                    $casos[] = $this->caso($a, 'infantil', "$primeiro: Educação Infantil não retém",
                        'Na Educação Infantil a avaliação é de acompanhamento, sem retenção (LDB, art. 31).',
                        [['promover', 'Segue para a próxima turma', "$primeiro segue com a turma."]]);
                }
                continue;
            }

            if ($pct !== null && $pct < RelatorioFrequenciaService::META && $temVaga) {
                $casos[] = $this->caso($a, 'frequencia', "$primeiro: frequência de " . $this->num($pct) . '%',
                    "{$f->presencas} presenças em {$f->dias} dias. A LDB exige 75% para aprovar, mesmo com as médias em dia.",
                    [
                        ['reter', 'Reter por frequência', "$primeiro fica retido (frequência abaixo de 75%)."],
                        ['promover', 'Aprovar (faltas compensadas)', "$primeiro é aprovado; registre a compensação de ausências."],
                    ]);
                continue;
            }

            if ($n && $temVaga) {
                $abaixo = array_filter($n['medias'], fn ($m) => $m !== null && $m < $minima);
                if (count($abaixo) === 1) {
                    $disc = array_key_first($abaixo);
                    $m = $abaixo[$disc];
                    if ($minima - $m <= 1.0) {
                        $porPouco[$disc][] = ['a' => $a, 'media' => $m];
                        continue;
                    }
                }
            }

            // Só as disciplinas em que a turma tem nota (as que a escola não avalia com nota não contam).
            $semNota = array_intersect_key($daTurma, array_diff_key($nomesDisc, array_filter($n['medias'] ?? [], fn ($m) => $m !== null)));
            if ($semNota && $temVaga) {
                $semNotas[] = $this->caso($a, 'sem_nota', "$primeiro: faltam notas", 'Sem nota no ano em ' . $this->lista(array_values($semNota))
                    . '. A sugestão pelas médias pode mudar quando as notas forem lançadas.', []);
            }
        }

        // Retidos por pouco: 3+ na mesma disciplina é padrão da turma (vale olhar a avaliação), não caso isolado.
        foreach ($porPouco as $disc => $lista) {
            $nomeDisc = $nomesDisc[$disc] ?? 'uma disciplina';
            if (count($lista) >= 3) {
                $n = count($lista);
                $nomes = array_map(fn ($x) => explode(' ', $x['a']['nome'])[0] . ' (' . $this->num($x['media']) . ')', $lista);
                $casos[] = [
                    'alunos' => array_map(fn ($x) => $x['a']['aluno_id'], $lista), 'tipo' => 'padrao',
                    'titulo' => "$n alunos retidos só em $nomeDisc",
                    'detalhe' => $this->lista($nomes) . '; mínima ' . $this->num($minima) . ". Tantos alunos perto da média na mesma disciplina "
                        . 'pode ser a avaliação, não os alunos: vale conversar com o professor antes de fechar.',
                    'opcoes' => [
                        ['acao' => 'promover', 'rotulo' => "Aprovar os $n pelo conselho", 'frase' => "Os $n alunos são aprovados pelo conselho de classe."],
                        ['acao' => 'reter', 'rotulo' => "Manter os $n retidos", 'frase' => "Os $n alunos ficam retidos."],
                    ],
                ];
                continue;
            }
            foreach ($lista as $x) {
                $primeiro = explode(' ', $x['a']['nome'])[0];
                $casos[] = $this->caso($x['a'], 'por_pouco', "$primeiro: retido por pouco em $nomeDisc",
                    'Média ' . $this->num($x['media']) . ' com mínima ' . $this->num($minima) . '; nas outras disciplinas está aprovado.',
                    [
                        ['promover', 'Aprovar pelo conselho de classe', "$primeiro é aprovado pelo conselho de classe."],
                        ['reter', 'Manter retido', "$primeiro fica retido."],
                    ]);
            }
        }
        return array_merge($casos, $semNotas);
    }

    /** @param list<array{0: string, 1: string, 2: string}> $opcoes [ação, rótulo, frase] */
    private function caso(array $a, string $tipo, string $titulo, string $detalhe, array $opcoes): array
    {
        return [
            'alunos' => [$a['aluno_id']], 'tipo' => $tipo, 'titulo' => $titulo, 'detalhe' => $detalhe,
            'opcoes' => array_map(fn ($o) => ['acao' => $o[0], 'rotulo' => $o[1], 'frase' => $o[2]], $opcoes),
        ];
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }

    private function lista(array $itens): string
    {
        if (count($itens) > 3) {
            return implode(', ', array_slice($itens, 0, 3)) . ' e mais ' . (count($itens) - 3);
        }
        return count($itens) > 1 ? implode(', ', array_slice($itens, 0, -1)) . ' e ' . end($itens) : ($itens[0] ?? '');
    }
}
