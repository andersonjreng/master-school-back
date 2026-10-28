<?php

namespace App\Services\Horarios;

use Illuminate\Support\Facades\DB;

/**
 * "Max, resolve pra mim" do quadro de horários.
 *
 * Choque = o mesmo professor em duas turmas no mesmo dia, em horários que se
 * sobrepõem. O resolvedor é determinístico (não usa IA): simula trocas de
 * casa dentro da turma (a aula vai para outro horário e a que estava lá vem
 * para o lugar dela) e só aceita a troca que acaba com o choque sem criar
 * outro. Quando uma troca só não basta, tenta uma segunda que resolva o
 * choque criado pela primeira. As opções vêm ordenadas pelo menor impacto:
 * menos trocas, mesma turma, mesmo dia, sem amontoar a mesma disciplina.
 */
class ResolvedorConflitos
{
    /** @var array<int, array> aula_id => aula (turma, dia, faixa, início, fim, professor...) */
    private array $aulas = [];
    /** @var array<int, array> turma_id => casas de aula (dia × faixa) */
    private array $casas = [];

    public function __construct(private HorarioService $horarios)
    {
    }

    /** Choques do ano letivo: lista de pares [aula_id, aula_id]. */
    public function conflitosDoAno(int $ano): array
    {
        $this->carregar($ano);
        return array_values($this->conflitos($this->aulas));
    }

    /**
     * Os choques da turma, cada um com até 3 opções de solução, e um plano que
     * resolve todos os choques do ano de uma vez.
     */
    public function analisar(int $turmaId): array
    {
        $ano = (int) DB::table('turmas')->where('id', $turmaId)->value('ano_letivo');
        $this->carregar($ano);
        $todos = $this->conflitos($this->aulas);

        $daTurma = array_filter($todos, fn ($p) => $this->aulas[$p[0]]['turma_id'] === $turmaId || $this->aulas[$p[1]]['turma_id'] === $turmaId);
        $conflitos = [];
        foreach ($daTurma as $chave => $par) {
            $opcoes = $this->opcoes($this->aulas, $par, $turmaId);
            $conflitos[] = [
                'sem_saida' => $opcoes ? null : $this->semSaida($par),
                'chave' => $chave,
                'professor' => $this->aulas[$par[0]]['professor'],
                'dia' => HorarioService::DIAS[$this->aulas[$par[0]]['dia']] ?? '',
                'aulas' => array_map(fn ($id) => $this->resumo($this->aulas[$id]), $this->ordenarPar($par, $turmaId)),
                'opcoes' => array_map(fn ($o) => $this->apresentar($o, $turmaId), $opcoes),
            ];
        }

        return [
            'ano' => $ano,
            'conflitos' => $conflitos,
            'total_ano' => count($todos),
            'plano' => count($todos) > 1 ? $this->planoGeral($todos, $turmaId) : null,
        ];
    }

    // ── Busca ──────────────────────────────────────────────────────────

    /** Resolve os choques um a um, sempre pela melhor opção, acumulando as trocas. */
    private function planoGeral(array $todos, int $turmaId): ?array
    {
        $estado = $this->aulas;
        $ops = [];
        $semSaida = [];
        $tentados = [];
        while ($pendentes = array_diff_key($this->conflitos($estado), $tentados)) {
            $chave = array_key_first($pendentes);
            $tentados[$chave] = true;
            $melhor = $this->opcoes($estado, $pendentes[$chave], $turmaId)[0] ?? null;
            if (!$melhor) {
                $semSaida[] = $chave;
                continue;
            }
            $ops = array_merge($ops, $melhor['ops']);
            $estado = $melhor['estado'];
        }
        if (!$ops) {
            return null;
        }
        $restantes = count($this->conflitos($estado));
        return $this->apresentar(['ops' => $ops, 'estado' => $estado, 'custo' => 0], $turmaId) + [
            'resolvidos' => count($todos) - $restantes,
            'restantes' => $restantes,
        ];
    }

    /**
     * Até 3 formas de desfazer o choque $par, da que mexe menos para a que mexe mais.
     * @return list<array{ops: array, estado: array, custo: int}>
     */
    private function opcoes(array $estado, array $par, int $foco): array
    {
        $antes = $this->conflitos($estado);
        $alvo = $this->chave($par[0], $par[1]);
        $achadas = [];

        foreach ($par as $id) {
            foreach ($this->movimentos($estado, $id) as $op) {
                $e1 = $this->aplicar($estado, $op);
                $depois = $this->conflitos($e1);
                if (isset($depois[$alvo])) {
                    continue;
                }
                $novos = array_diff_key($depois, $antes);
                if (!$novos) {
                    $achadas[] = ['ops' => [$op], 'estado' => $e1];
                    continue;
                }
                // A troca criou exatamente um choque novo: uma segunda troca, em outra aula, pode resolvê-lo.
                if (count($novos) !== 1) {
                    continue;
                }
                $mexidas = array_filter([$op['origem_id'], $op['destino_id']]);
                foreach (reset($novos) as $id2) {
                    if (in_array($id2, $mexidas, true)) {
                        continue;
                    }
                    foreach ($this->movimentos($e1, $id2) as $op2) {
                        if (in_array($op2['destino_id'], $mexidas, true)) {
                            continue;
                        }
                        $e2 = $this->aplicar($e1, $op2);
                        $depois2 = $this->conflitos($e2);
                        if (!isset($depois2[$alvo]) && !array_diff_key($depois2, $antes)) {
                            $achadas[] = ['ops' => [$op, $op2], 'estado' => $e2];
                        }
                    }
                }
            }
        }

        foreach ($achadas as &$o) {
            $o['custo'] = $this->custo($estado, $o, $foco);
        }
        unset($o);
        usort($achadas, fn ($a, $b) => $a['custo'] <=> $b['custo']);

        // Opções diferentes de verdade: sem repetir a mesma grade final.
        $vistas = [];
        $saida = [];
        foreach ($achadas as $o) {
            $assinatura = implode('|', array_map(fn ($op) => "{$op['origem_id']}>{$op['para']['dia']}:{$op['para']['faixa_id']}", $o['ops']));
            $grade = md5(json_encode(array_map(fn ($a) => [$a['dia'], $a['faixa_id']], array_intersect_key($o['estado'], array_flip($this->mexidas($o['ops']))))));
            if (isset($vistas[$assinatura]) || isset($vistas[$grade])) {
                continue;
            }
            $vistas[$assinatura] = $vistas[$grade] = true;
            $saida[] = $o;
            if (count($saida) === 3) {
                break;
            }
        }
        return $saida;
    }

    /** Todas as casas da turma para onde a aula pode ir (vaga ou trocando de lugar com outra). */
    private function movimentos(array $estado, int $aulaId): array
    {
        $a = $estado[$aulaId];
        $ocupadas = [];
        foreach ($estado as $x) {
            if ($x['turma_id'] === $a['turma_id']) {
                $ocupadas[$x['dia'] . ':' . $x['faixa_id']] = $x['id'];
            }
        }
        $ops = [];
        foreach ($this->casas[$a['turma_id']] ?? [] as $c) {
            if ($c['dia'] === $a['dia'] && $c['faixa_id'] === $a['faixa_id']) {
                continue;
            }
            $destino = $ocupadas[$c['dia'] . ':' . $c['faixa_id']] ?? null;
            if ($destino && $estado[$destino]['disciplina_id'] === $a['disciplina_id']) {
                continue; // trocar Matemática por Matemática não muda nada
            }
            $ops[] = [
                'turma_id' => $a['turma_id'],
                'de' => ['dia' => $a['dia'], 'faixa_id' => $a['faixa_id']],
                'para' => ['dia' => $c['dia'], 'faixa_id' => $c['faixa_id']],
                'origem_id' => $a['id'], 'destino_id' => $destino,
            ];
        }
        return $ops;
    }

    private function aplicar(array $estado, array $op): array
    {
        $casa = fn ($turma, $c) => collect($this->casas[$turma])->first(fn ($x) => $x['dia'] === $c['dia'] && $x['faixa_id'] === $c['faixa_id']);
        $para = $casa($op['turma_id'], $op['para']);
        $de = $casa($op['turma_id'], $op['de']);
        $estado[$op['origem_id']] = array_merge($estado[$op['origem_id']], ['dia' => $para['dia'], 'faixa_id' => $para['faixa_id'], 'ordem' => $para['ordem'], 'inicio' => $para['inicio'], 'fim' => $para['fim']]);
        if ($op['destino_id']) {
            $estado[$op['destino_id']] = array_merge($estado[$op['destino_id']], ['dia' => $de['dia'], 'faixa_id' => $de['faixa_id'], 'ordem' => $de['ordem'], 'inicio' => $de['inicio'], 'fim' => $de['fim']]);
        }
        return $estado;
    }

    /**
     * Quanto a opção mexe na rotina: cada troca pesa; mudar de dia, mexer em
     * outra turma e deixar a mesma disciplina 3+ vezes no dia pesam mais.
     */
    private function custo(array $antes, array $opcao, int $foco): int
    {
        $custo = count($opcao['ops']) * 10;
        foreach ($opcao['ops'] as $op) {
            if ($op['turma_id'] !== $foco) {
                $custo += 4;
            }
            if ($op['de']['dia'] !== $op['para']['dia']) {
                $custo += 2;
            }
        }
        $turmas = array_unique(array_column($opcao['ops'], 'turma_id'));
        $custo += 6 * max(0, $this->amontoado($opcao['estado'], $turmas) - $this->amontoado($antes, $turmas));
        return $custo;
    }

    /** Aulas da mesma disciplina além de 2 no mesmo dia, somadas nas turmas. */
    private function amontoado(array $estado, array $turmas): int
    {
        $n = [];
        foreach ($estado as $a) {
            if (in_array($a['turma_id'], $turmas, true)) {
                $k = "{$a['turma_id']}:{$a['dia']}:{$a['disciplina_id']}";
                $n[$k] = ($n[$k] ?? 0) + 1;
            }
        }
        return array_sum(array_map(fn ($v) => max(0, $v - 2), $n));
    }

    /** @return array<string, array{0: int, 1: int}> chave "menor-maior" => par de aulas */
    private function conflitos(array $estado): array
    {
        $grupos = [];
        foreach ($estado as $a) {
            if ($a['professor_id']) {
                $grupos[$a['professor_id'] . ':' . $a['dia']][] = $a;
            }
        }
        $pares = [];
        foreach ($grupos as $lista) {
            $n = count($lista);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$x, $y] = [$lista[$i], $lista[$j]];
                    if ($x['turma_id'] !== $y['turma_id'] && $x['inicio'] < $y['fim'] && $y['inicio'] < $x['fim']) {
                        $pares[$this->chave($x['id'], $y['id'])] = [min($x['id'], $y['id']), max($x['id'], $y['id'])];
                    }
                }
            }
        }
        ksort($pares);
        return $pares;
    }

    // ── Apresentação ───────────────────────────────────────────────────

    /** Por que nenhuma troca resolve: o professor tem mais aulas do que horários no turno, ou a grade está travada. */
    private function semSaida(array $par): string
    {
        $a = $this->aulas[$par[0]];
        $turmas = [$a['turma_id'], $this->aulas[$par[1]]['turma_id']];
        $horarios = [];
        foreach ($turmas as $t) {
            foreach ($this->casas[$t] ?? [] as $c) {
                $horarios[$c['dia'] . $c['inicio']] = true;
            }
        }
        $aulas = count(array_filter($this->aulas, fn ($x) => $x['professor_id'] === $a['professor_id'] && in_array($x['turma_id'], $turmas, true)));
        $primeiro = explode(' ', (string) $a['professor'])[0];
        return $aulas > count($horarios)
            ? "$primeiro tem $aulas aulas por semana nessas duas turmas, mas o turno só tem " . count($horarios) . ' horários. Não cabe: uma das turmas precisa de outro professor.'
            : 'Não achei uma troca que desfaça esse choque sem criar outro. Dá para mover à mão ou trocar o professor de uma das aulas.';
    }

    /** Opção pronta para a tela: as trocas (para aplicar), em frases, e a prévia da turma em foco. */
    private function apresentar(array $o, int $foco): array
    {
        $frases = [];
        foreach ($o['ops'] as $op) {
            $a = $this->aulas[$op['origem_id']];
            $para = $o['estado'][$op['origem_id']];
            $frase = "{$a['turma']}: {$a['disciplina']} sai de {$this->quando($a)} e vai para {$this->quando($para)}";
            if ($op['destino_id']) {
                $b = $this->aulas[$op['destino_id']];
                $frase .= ", trocando de lugar com {$b['disciplina']}";
            } else {
                $frase .= ' (horário vago)';
            }
            $frases[] = $frase . '.';
        }
        $conflitosDepois = [];
        foreach ($this->conflitos($o['estado']) as $par) {
            $conflitosDepois[$par[0]] = $conflitosDepois[$par[1]] = true;
        }
        return [
            'ops' => $o['ops'],
            'mudancas' => $frases,
            'outras_turmas' => array_values(array_unique(array_map(fn ($op) => $this->aulas[$op['origem_id']]['turma'],
                array_filter($o['ops'], fn ($op) => $op['turma_id'] !== $foco)))),
            'previa' => array_values(array_map(fn ($a) => ['id' => $a['id'], 'dia' => $a['dia'], 'faixa_id' => $a['faixa_id'], 'conflito' => isset($conflitosDepois[$a['id']])],
                array_filter($o['estado'], fn ($a) => $a['turma_id'] === $foco))),
            'mexidas' => $this->mexidas($o['ops']),
        ];
    }

    private function resumo(array $a): array
    {
        return ['id' => $a['id'], 'turma_id' => $a['turma_id'], 'turma' => $a['turma'], 'disciplina' => $a['disciplina'],
            'dia' => $a['dia'], 'inicio' => substr($a['inicio'], 0, 5), 'fim' => substr($a['fim'], 0, 5)];
    }

    private function quando(array $a): string
    {
        return mb_strtolower(HorarioService::DIAS[$a['dia']] ?? '') . ' ' . substr($a['inicio'], 0, 5);
    }

    private function mexidas(array $ops): array
    {
        return array_values(array_unique(array_filter(array_merge(array_column($ops, 'origem_id'), array_column($ops, 'destino_id')))));
    }

    /** A aula da turma em foco primeiro. */
    private function ordenarPar(array $par, int $turmaId): array
    {
        return $this->aulas[$par[0]]['turma_id'] === $turmaId ? $par : [$par[1], $par[0]];
    }

    private function chave(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    private function carregar(int $ano): void
    {
        $e = $this->horarios->estadoDoAno($ano);
        $this->casas = $e['casas'];
        $ordem = [];
        foreach ($this->casas as $lista) {
            foreach ($lista as $c) {
                $ordem[$c['faixa_id']] = $c['ordem'];
            }
        }
        $this->aulas = array_map(fn ($a) => $a + ['ordem' => $ordem[$a['faixa_id']] ?? 0], $e['aulas']);
    }
}
