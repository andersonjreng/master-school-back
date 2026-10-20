<?php

namespace App\Services\Professores;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Painel "Meu dia" da home do professor, num único payload: período letivo
 * atual, situação de hoje em cada turma, indicadores com tendência,
 * pendências acionáveis, próximas avaliações e alunos em atenção.
 *
 * Escopo: turmas ATIVAS do ano letivo em andamento (o mais antigo com turma
 * ativa — mesma regra de Minhas Turmas), e só as disciplinas que o professor
 * leciona em cada uma. Dias letivos esperados = calendário escolar (bimestres − feriados/recessos).
 */
class PainelProfessorService
{
    private const META_FREQUENCIA = 75.0;
    private const DIAS_PENDENCIA_CHAMADA = 10; // dias letivos olhados para trás

    public function gerar(int $professorId): array
    {
        $hoje = Carbon::today();

        $vinculos = DB::table('turma_professor_disciplina as tpd')
            ->join('turmas as t', 't.id', '=', 'tpd.turma_id')
            ->join('disciplinas as d', 'd.id', '=', 'tpd.disciplina_id')
            ->where('tpd.professor_id', $professorId)
            ->where('t.status', 'Ativa')
            ->orderBy('t.nome_turma')
            ->get(['t.id as turma_id', 't.nome_turma', 't.ano_letivo', 't.turno', 't.sistema_avaliacao_id', 'd.id as disciplina_id', 'd.nome_disciplina']);

        if ($vinculos->isEmpty()) {
            return $this->vazio();
        }

        $ano = (int) $vinculos->min('ano_letivo');
        $vinculos = $vinculos->where('ano_letivo', $ano);
        $periodo = app(\App\Services\Turmas\PeriodoLetivoService::class)->periodo($ano, $hoje);

        // Início da contagem de chamadas: início do ano letivo (1º bimestre) ou 1º de fevereiro.
        $inicioAno = $periodo['inicio_ano'] ? Carbon::parse($periodo['inicio_ano']) : Carbon::create($ano, 2, 1);
        $inicioMes = $hoje->copy()->startOfMonth()->max($inicioAno);
        $calendario = app(\App\Services\Turmas\CalendarioEscolarService::class);
        $mapa = $calendario->mapa($ano);
        $hojeLetivo = $calendario->ehLetivo($hoje->toDateString(), $mapa);

        $turmas = [];
        $pendencias = [];
        $proximas = [];
        $atencao = [];
        $chamadasEsperadas = 0;
        $chamadasLancadas = 0;
        $avaliacoesAplicadas = 0;
        $avaliacoesCompletas = 0;
        $recentes = [];

        foreach ($vinculos->groupBy('turma_id') as $turmaId => $linhas) {
            $turma = $linhas->first();
            $disciplinaIds = $linhas->pluck('disciplina_id')->map(fn ($id) => (int) $id)->all();
            $roster = $this->roster((int) $turmaId);

            // ── Chamadas (frequência) ─────────────────────────────
            $datasComChamada = $roster ? DB::table('frequencia')
                ->whereIn('aluno_id', $roster)
                ->whereBetween('data', [$inicioAno->toDateString(), $hoje->toDateString()])
                ->distinct()->pluck('data')->map(fn ($d) => substr((string) $d, 0, 10))->flip()->all() : [];

            $chamadaHojeLancada = isset($datasComChamada[$hoje->toDateString()]);
            $presentesHoje = $chamadaHojeLancada
                ? DB::table('frequencia')->whereIn('aluno_id', $roster)->where('data', $hoje->toDateString())->where('presente', 1)->count()
                : 0;

            // Mês: dias letivos já passados + hoje se já foi lançada (não penaliza a manhã).
            foreach ($calendario->diasLetivos($mapa, $inicioMes, $hoje->copy()->subDay()) as $dia) {
                $chamadasEsperadas++;
                if (isset($datasComChamada[$dia])) {
                    $chamadasLancadas++;
                }
            }
            if ($hojeLetivo && $chamadaHojeLancada && $hoje->gte($inicioAno)) {
                $chamadasEsperadas++;
                $chamadasLancadas++;
            }

            // Dias letivos recentes sem chamada → pendência (feriado/recesso não conta).
            $janela = $calendario->ultimosLetivos($mapa, $hoje, self::DIAS_PENDENCIA_CHAMADA, $inicioAno);
            $semChamada = array_values(array_filter($janela, fn ($d) => !isset($datasComChamada[$d])));
            if ($roster && $semChamada) {
                $n = count($semChamada);
                $pendencias[] = [
                    'tipo'      => 'chamada_atrasada',
                    'severidade' => $n >= 3 ? 'danger' : 'warning',
                    'icone'     => 'pi-calendar-times',
                    'titulo'    => "$n dia(s) sem chamada em {$turma->nome_turma}",
                    'descricao' => 'Último dia sem chamada: ' . Carbon::parse(end($semChamada))->format('d/m') . ($n > 1 ? ' · primeiro: ' . Carbon::parse($semChamada[0])->format('d/m') : ''),
                    'acao'      => 'Lançar chamada',
                    'rota'      => '/frequencia',
                    'query'     => ['turma_id' => (int) $turmaId, 'nome_turma' => $turma->nome_turma],
                    'pergunta'  => "Quais dias estão sem chamada na turma {$turma->nome_turma}?",
                ];
            }

            // ── Registro de aula de hoje ──────────────────────────
            $registrosHoje = DB::table('registros_aula')
                ->where('turma_id', $turmaId)->where('professor_id', $professorId)->where('data', $hoje->toDateString())
                ->count();

            // ── Avaliações e notas ───────────────────────────────
            $avaliacoes = DB::table('avaliacoes as a')
                ->join('tipos_avaliacao as ta', 'ta.id', '=', 'a.tipo_avaliacao_id')
                ->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
                ->leftJoin('unidades_letivas as u', 'u.id', '=', 'a.unidade_letiva_id')
                ->where('a.turma_id', $turmaId)
                ->whereIn('a.disciplina_id', $disciplinaIds)
                ->where('u.ano_letivo', $ano)
                ->orderBy('a.data_aplicacao')
                ->get(['a.id', 'a.data_aplicacao', 'a.descricao', 'a.disciplina_id', 'a.professor_id', 'ta.nome_tipo', 'ta.cor', 'd.nome_disciplina']);

            $notasPorAvaliacao = $avaliacoes->isNotEmpty()
                ? DB::table('notas')->whereIn('avaliacao_id', $avaliacoes->pluck('id'))->whereIn('aluno_id', $roster ?: [0])
                    ->select('avaliacao_id', DB::raw('COUNT(*) AS total'))->groupBy('avaliacao_id')->pluck('total', 'avaliacao_id')
                : collect();

            $proximaDaTurma = null;
            $notasTurma = ['aplicadas' => 0, 'completas' => 0];
            foreach ($avaliacoes as $av) {
                $data = Carbon::parse($av->data_aplicacao);
                if ($data->gte($hoje)) {
                    $item = [
                        'avaliacao_id' => (int) $av->id,
                        'data'         => $data->toDateString(),
                        'dias'         => $this->dias($hoje, $data),
                        'tipo'         => $av->nome_tipo,
                        'cor'          => $av->cor,
                        'disciplina'   => $av->nome_disciplina,
                        'turma_id'     => (int) $turmaId,
                        'turma'        => $turma->nome_turma,
                        'observacao'   => $av->descricao ?: null,
                    ];
                    $proximaDaTurma ??= $item;
                    if ($item['dias'] <= 30) {
                        $proximas[] = $item;
                    }
                    continue;
                }

                $avaliacoesAplicadas++;
                $notasTurma['aplicadas']++;
                $lancadas = (int) ($notasPorAvaliacao[$av->id] ?? 0);
                $queryNotas = [
                    'turma_id' => (int) $turmaId, 'nome_turma' => $turma->nome_turma, 'avaliacao_id' => (int) $av->id,
                    'disciplina_id' => (int) $av->disciplina_id, 'professor_id' => (int) $av->professor_id,
                    'origem_turma_id' => (int) $turmaId, 'origem_nome_turma' => $turma->nome_turma,
                ];
                // Avaliações já aplicadas (aba "Notas" do painel).
                $recentes[] = [
                    'avaliacao_id' => (int) $av->id, 'data' => $data->toDateString(), 'tipo' => $av->nome_tipo, 'cor' => $av->cor,
                    'disciplina' => $av->nome_disciplina, 'turma_id' => (int) $turmaId, 'turma' => $turma->nome_turma,
                    'lancadas' => min($lancadas, count($roster)), 'total' => count($roster), 'query' => $queryNotas,
                ];
                if ($lancadas >= count($roster)) {
                    $avaliacoesCompletas++;
                    $notasTurma['completas']++;
                    continue;
                }
                $pendencias[] = [
                    'tipo'       => 'notas',
                    'severidade' => $this->dias($hoje, $data) > 14 ? 'danger' : 'warning',
                    'icone'      => 'pi-file-edit',
                    'titulo'     => "Notas de {$av->nome_tipo} · {$av->nome_disciplina}",
                    'descricao'  => "{$turma->nome_turma} · aplicada em " . $data->format('d/m') . " · $lancadas de " . count($roster) . ' lançadas',
                    'acao'       => 'Lançar notas',
                    'rota'       => '/notas',
                    'query'      => [
                        'turma_id' => (int) $turmaId, 'nome_turma' => $turma->nome_turma, 'avaliacao_id' => (int) $av->id,
                        'disciplina_id' => (int) $av->disciplina_id, 'professor_id' => (int) $av->professor_id,
                        'origem_turma_id' => (int) $turmaId, 'origem_nome_turma' => $turma->nome_turma,
                    ],
                    'pergunta'   => "Como lanço as notas de {$av->nome_tipo} de {$av->nome_disciplina} na turma {$turma->nome_turma}?",
                ];
            }

            // ── Frequência da turma e alunos em atenção ──────────
            [$freqMes, $alunosTurma] = $this->frequenciaTurma($roster, $inicioAno, $hoje);
            foreach ($alunosTurma as $a) {
                if ($a['percentual'] < self::META_FREQUENCIA || $a['faltas_seguidas'] >= 3) {
                    $atencao[] = $a + ['turma_id' => (int) $turmaId, 'turma' => $turma->nome_turma];
                }
            }

            $turmas[] = [
                'turma_id'         => (int) $turmaId,
                'nome_turma'       => $turma->nome_turma,
                'turno'            => $turma->turno,
                'bncc'             => (int) $turma->sistema_avaliacao_id === 3,
                'alunos'           => count($roster),
                'disciplinas'      => $linhas->map(fn ($l) => ['id' => (int) $l->disciplina_id, 'nome' => $l->nome_disciplina])->values(),
                'chamada_hoje'     => ['dia_letivo' => $hojeLetivo, 'lancada' => $chamadaHojeLancada, 'presentes' => $presentesHoje, 'total' => count($roster)],
                'registros_hoje'   => ['lancados' => $registrosHoje, 'total' => count($disciplinaIds)],
                'proxima_avaliacao' => $proximaDaTurma,
                'frequencia_mes'   => $freqMes,
                'notas'            => $notasTurma,
                'alunos_atencao'   => count(array_filter($alunosTurma, fn ($a) => $a['percentual'] < self::META_FREQUENCIA || $a['faltas_seguidas'] >= 3)),
            ];
        }

        usort($proximas, fn ($a, $b) => [$a['data'], $a['turma']] <=> [$b['data'], $b['turma']]);
        usort($atencao, fn ($a, $b) => [$b['faltas_seguidas'], $a['percentual']] <=> [$a['faltas_seguidas'], $b['percentual']]);
        $ordemSev = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($pendencias, fn ($a, $b) => $ordemSev[$a['severidade']] <=> $ordemSev[$b['severidade']]);

        $rosterTodas = collect($turmas)->flatMap(fn ($t) => $this->roster($t['turma_id']))->unique()->values()->all();

        return [
            'ano_letivo'   => $ano,
            'hoje'         => $hoje->toDateString(),
            'periodo'      => $periodo,
            'indicadores'  => [
                'chamadas' => ['lancadas' => $chamadasLancadas, 'esperadas' => $chamadasEsperadas],
                'notas'    => ['completas' => $avaliacoesCompletas, 'aplicadas' => $avaliacoesAplicadas],
                'frequencia' => $this->frequenciaComparada($rosterTodas, $hoje, $inicioAno),
                'proxima_avaliacao' => $proximas[0] ?? null,
            ],
            'turmas'       => $turmas,
            'pendencias'   => array_slice($pendencias, 0, 8),
            'total_pendencias' => count($pendencias),
            'proximas_avaliacoes' => array_slice($proximas, 0, 8),
            'alunos_atencao' => array_slice($atencao, 0, 30),
            // Mais recentes primeiro; incompletas antes das completas no mesmo dia.
            'avaliacoes_aplicadas' => collect($recentes)->sortBy([['data', 'desc'], fn ($a, $b) => ($a['lancadas'] >= $a['total']) <=> ($b['lancadas'] >= $b['total'])])->take(20)->values()->all(),
            'total_alunos_atencao' => count($atencao),
        ];
    }

    /** Dias inteiros entre duas datas (Carbon 3 devolve diferença com sinal e decimal). */
    private function dias(Carbon $a, Carbon $b): int
    {
        return (int) abs(round($a->diffInDays($b)));
    }

    private function vazio(): array
    {
        return [
            'ano_letivo' => null, 'hoje' => Carbon::today()->toDateString(), 'periodo' => null,
            'indicadores' => null, 'turmas' => [], 'pendencias' => [], 'total_pendencias' => 0,
            'proximas_avaliacoes' => [], 'alunos_atencao' => [], 'total_alunos_atencao' => 0,
        ];
    }

    /** @return int[] alunos ativos vinculados à turma */
    private function roster(int $turmaId): array
    {
        return DB::table('turma_aluno as ta')
            ->join('alunos as a', 'a.id', '=', 'ta.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('ta.turma_id', $turmaId)->where('u.ativo', 1)
            ->pluck('ta.aluno_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Frequência do mês da turma e, por aluno no ano, percentual e faltas
     * seguidas mais recentes.
     *
     * @return array{0: ?float, 1: array}
     */
    private function frequenciaTurma(array $roster, Carbon $inicioAno, Carbon $hoje): array
    {
        if (!$roster) {
            return [null, []];
        }

        $registros = DB::table('frequencia as f')
            ->join('alunos as a', 'a.id', '=', 'f.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->whereIn('f.aluno_id', $roster)
            ->whereBetween('f.data', [$inicioAno->toDateString(), $hoje->toDateString()])
            ->orderBy('f.data')
            ->get(['f.aluno_id', 'u.nome_completo', 'f.data', 'f.presente']);

        $mes = $registros->filter(fn ($r) => substr((string) $r->data, 0, 7) === $hoje->format('Y-m'));
        $freqMes = $mes->count() ? round($mes->where('presente', 1)->count() / $mes->count() * 100, 1) : null;

        $alunos = [];
        foreach ($registros->groupBy('aluno_id') as $alunoId => $linhas) {
            $presencas = $linhas->where('presente', 1)->count();
            $seq = 0;
            foreach ($linhas->reverse() as $l) {
                if ((int) $l->presente === 1) {
                    break;
                }
                $seq++;
            }
            $alunos[] = [
                'aluno_id'        => (int) $alunoId,
                'nome'            => $linhas->first()->nome_completo,
                'percentual'      => round($presencas / $linhas->count() * 100, 1),
                'faltas'          => $linhas->count() - $presencas,
                'faltas_seguidas' => $seq,
            ];
        }

        return [$freqMes, $alunos];
    }

    /** Frequência média do mês atual vs. anterior + série semanal (8 semanas). */
    private function frequenciaComparada(array $roster, Carbon $hoje, Carbon $inicioAno): array
    {
        if (!$roster) {
            return ['atual' => null, 'anterior' => null, 'semanas' => []];
        }

        $pct = function (Carbon $de, Carbon $ate) use ($roster) {
            $r = DB::table('frequencia')->whereIn('aluno_id', $roster)->whereBetween('data', [$de->toDateString(), $ate->toDateString()])
                ->selectRaw('SUM(presente = 1) AS p, COUNT(*) AS t')->first();
            return (int) $r->t > 0 ? round($r->p / $r->t * 100, 1) : null;
        };

        $semanas = [];
        for ($i = 7; $i >= 0; $i--) {
            $ini = $hoje->copy()->startOfWeek()->subWeeks($i);
            if ($ini->endOfWeek()->lt($inicioAno)) {
                continue;
            }
            $ini = $hoje->copy()->startOfWeek()->subWeeks($i);
            $semanas[] = ['inicio' => $ini->toDateString(), 'percentual' => $pct($ini, $ini->copy()->endOfWeek())];
        }

        return [
            'atual'    => $pct($hoje->copy()->startOfMonth(), $hoje),
            'anterior' => $pct($hoje->copy()->subMonthNoOverflow()->startOfMonth(), $hoje->copy()->subMonthNoOverflow()->endOfMonth()),
            'semanas'  => $semanas,
        ];
    }
}
