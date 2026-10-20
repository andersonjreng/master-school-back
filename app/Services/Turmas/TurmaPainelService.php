<?php

namespace App\Services\Turmas;

use App\Services\Alunos\FotoAlunoService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Situação "de hoje" das turmas (tela Minhas Turmas): chamada, registros,
 * frequência do mês, notas lançadas, próxima avaliação e alunos em atenção;
 * e o resumo de uma turma (alunos com frequência/média e avaliações) para a
 * gaveta de detalhes. Com $professorId, olha só as disciplinas dele.
 */
class TurmaPainelService
{
    public function __construct(private CalendarioEscolarService $calendario) {}

    private const META_FREQUENCIA = 75.0;
    private const JANELA_CHAMADA = 10; // dias úteis olhados para trás

    /** @return array<int, array> situação por turma_id (só turmas ativas) */
    public function situacao(?int $professorId): array
    {
        $hoje = Carbon::today();

        $turmas = DB::table('turmas')->where('status', 'Ativa')
            ->when($professorId, fn ($q) => $q->whereIn('id',
                DB::table('turma_professor_disciplina')->where('professor_id', $professorId)->select('turma_id')))
            ->get(['id', 'nome_turma', 'ano_letivo']);

        $saida = [];
        foreach ($turmas->groupBy('ano_letivo') as $ano => $doAno) {
            $inicioAno = $this->inicioAno((int) $ano, $hoje);
            $mapa = $this->calendario->mapa((int) $ano);
            foreach ($doAno as $t) {
                $saida[(int) $t->id] = $this->situacaoTurma($t, $professorId, $inicioAno, $hoje, $mapa);
            }
        }
        return $saida;
    }

    private function situacaoTurma(object $t, ?int $professorId, Carbon $inicioAno, Carbon $hoje, array $mapa): array
    {
        $turmaId = (int) $t->id;
        $roster = $this->rosterAtivo($turmaId);

        // Últimos dias letivos (calendário escolar: sem feriados/recessos)
        $janela = $this->calendario->ultimosLetivos($mapa, $hoje, self::JANELA_CHAMADA, $inicioAno);
        $desde = min($janela[0] ?? $hoje->toDateString(), $hoje->copy()->startOfWeek()->toDateString());

        // Chamadas: datas com algum registro de frequência da turma
        $datas = $roster ? DB::table('frequencia')->whereIn('aluno_id', $roster)
            ->whereBetween('data', [$desde, $hoje->toDateString()])
            ->distinct()->pluck('data')->map(fn ($d) => substr((string) $d, 0, 10))->flip()->all() : [];

        $lancadaHoje = isset($datas[$hoje->toDateString()]);
        $presentes = $lancadaHoje
            ? DB::table('frequencia')->whereIn('aluno_id', $roster)->where('data', $hoje->toDateString())->where('presente', 1)->count()
            : 0;

        // Semana corrente (seg–sex, + sábado letivo): chamada feita, faltando,
        // ainda por vir ou dia sem aula (feriado/recesso/fora do bimestre).
        $semana = [];
        $seg = $hoje->copy()->startOfWeek();
        foreach (range(0, 5) as $i) {
            $dia = $seg->copy()->addDays($i)->toDateString();
            $tipo = $this->calendario->classificar($dia, $mapa);
            if ($i === 5 && $tipo !== 'sabado_letivo') {
                continue;
            }
            $letivo = in_array($tipo, ['letivo', 'sabado_letivo'], true);
            $semana[] = [
                'data'   => $dia,
                'status' => isset($datas[$dia]) ? 'ok' : (!$letivo ? 'nao_letivo'
                    : ($dia > $hoje->toDateString() || $dia < $inicioAno->toDateString() || !$roster ? 'futuro'
                    : ($dia === $hoje->toDateString() ? 'hoje' : 'falta'))),
                'motivo' => $letivo ? null : ($this->calendario->motivo($dia, $mapa) ?? ($tipo === 'fora' ? 'Fora do período letivo' : null)),
            ];
        }

        $semChamada = $roster ? count(array_filter($janela, fn ($d) => !isset($datas[$d]))) : 0;

        // Registros de aula de hoje (do professor, quando for o professor)
        $disciplinas = $this->disciplinasDaTurma($turmaId, $professorId);
        $registros = DB::table('registros_aula')->where('turma_id', $turmaId)->where('data', $hoje->toDateString())
            ->when($professorId, fn ($q) => $q->where('professor_id', $professorId))
            ->count();

        // Frequência do mês e alunos abaixo da meta no ano
        $freqMes = null;
        $atencao = 0;
        if ($roster) {
            $m = DB::table('frequencia')->whereIn('aluno_id', $roster)
                ->whereBetween('data', [$hoje->copy()->startOfMonth()->max($inicioAno)->toDateString(), $hoje->toDateString()])
                ->selectRaw('SUM(presente = 1) AS p, COUNT(*) AS t')->first();
            $freqMes = (int) $m->t > 0 ? round($m->p / $m->t * 100, 1) : null;

            $atencao = DB::table('frequencia')->whereIn('aluno_id', $roster)
                ->whereBetween('data', [$inicioAno->toDateString(), $hoje->toDateString()])
                ->groupBy('aluno_id')
                ->havingRaw('SUM(presente = 1) / COUNT(*) * 100 < ?', [self::META_FREQUENCIA])
                ->select('aluno_id')->get()->count();
        }

        // Avaliações do ano: aplicadas x completas e a próxima
        $avaliacoes = $this->avaliacoes($turmaId, (int) $t->ano_letivo, $disciplinas->pluck('disciplina_id')->all());
        $lancadas = $this->notasLancadas($avaliacoes, $roster);
        $aplicadas = $avaliacoes->filter(fn ($a) => Carbon::parse($a->data_aplicacao)->lte($hoje));
        $completas = $aplicadas->filter(fn ($a) => count($roster) > 0 && ($lancadas[$a->id] ?? 0) >= count($roster))->count();
        $proxima = $avaliacoes->first(fn ($a) => Carbon::parse($a->data_aplicacao)->gte($hoje));

        return [
            'turma_id'        => $turmaId,
            'alunos'          => count($roster),
            'dia_letivo'      => $this->calendario->ehLetivo($hoje->toDateString(), $mapa) && $hoje->gte($inicioAno),
            'chamada_hoje'    => ['lancada' => $lancadaHoje, 'presentes' => $presentes, 'total' => count($roster)],
            'registros_hoje'  => ['lancados' => $registros, 'total' => $professorId ? $disciplinas->count() : null],
            'dias_sem_chamada' => $semChamada,
            'semana'          => $semana,
            'frequencia_mes'  => $freqMes,
            'alunos_atencao'  => $atencao,
            'notas'           => ['aplicadas' => $aplicadas->count(), 'completas' => $completas, 'pendentes' => $aplicadas->count() - $completas],
            'proxima_avaliacao' => $proxima ? [
                'data'       => substr((string) $proxima->data_aplicacao, 0, 10),
                'dias'       => (int) abs(round($hoje->diffInDays(Carbon::parse($proxima->data_aplicacao)))),
                'tipo'       => $proxima->nome_tipo,
                'cor'        => $proxima->cor,
                'disciplina' => $proxima->nome_disciplina,
            ] : null,
        ];
    }

    /** Resumo de uma turma para a gaveta: alunos, avaliações e disciplinas. */
    public function resumo(int $turmaId, ?int $professorId): ?array
    {
        $t = DB::table('turmas')->where('id', $turmaId)->first(['id', 'nome_turma', 'ano_letivo', 'status', 'sistema_avaliacao_id']);
        if (!$t) {
            return null;
        }
        $hoje = Carbon::today();
        $ano = (int) $t->ano_letivo;
        $inicioAno = $this->inicioAno($ano, $hoje);
        $encerrada = $t->status !== 'Ativa';
        $ate = $encerrada ? Carbon::create($ano, 12, 31)->min($hoje) : $hoje;

        // Encerrada: os alunos vêm do histórico de matrículas.
        $alunosIds = DB::table('turma_aluno')->where('turma_id', $turmaId)->pluck('aluno_id')
            ->merge($encerrada ? DB::table('matriculas')->where('turma_id', $turmaId)->pluck('aluno_id') : [])
            ->map(fn ($id) => (int) $id)->unique()->values()->all();

        $alunos = $alunosIds ? DB::table('alunos as a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->whereIn('a.id', $alunosIds)->when(!$encerrada, fn ($q) => $q->where('u.ativo', 1))
            ->orderBy('u.nome_completo')->get(['a.id', 'u.nome_completo', 'a.foto_path', 'a.matricula']) : collect();
        $ids = $alunos->pluck('id')->map(fn ($id) => (int) $id)->all();

        $freq = $ids ? DB::table('frequencia')->whereIn('aluno_id', $ids)
            ->whereBetween('data', [$inicioAno->toDateString(), $ate->toDateString()])
            ->orderBy('data')->get(['aluno_id', 'presente'])->groupBy('aluno_id') : collect();

        $disciplinas = $this->disciplinasDaTurma($turmaId, null);
        $minhas = $professorId ? $disciplinas->where('professor_id', $professorId)->pluck('disciplina_id')->all() : $disciplinas->pluck('disciplina_id')->all();
        $avaliacoes = $this->avaliacoes($turmaId, $ano, $minhas);
        $lancadas = $this->notasLancadas($avaliacoes, $ids);

        // Média por aluno: notas lançadas normalizadas para 0–10.
        $notas = $avaliacoes->isNotEmpty() && $ids ? DB::table('notas as n')->join('avaliacoes as a', 'a.id', '=', 'n.avaliacao_id')
            ->whereIn('n.avaliacao_id', $avaliacoes->pluck('id'))->whereIn('n.aluno_id', $ids)->whereNotNull('n.nota_valor')
            ->where('a.valor_maximo', '>', 0)
            ->selectRaw('n.aluno_id, AVG(n.nota_valor / a.valor_maximo * 10) AS media, COUNT(*) AS qtd')
            ->groupBy('n.aluno_id')->get()->keyBy('aluno_id') : collect();

        $listaAlunos = $alunos->map(function ($a) use ($freq, $notas) {
            $linhas = $freq->get($a->id, collect());
            $pres = $linhas->where('presente', 1)->count();
            $seq = 0;
            foreach ($linhas->reverse() as $l) {
                if ((int) $l->presente === 1) {
                    break;
                }
                $seq++;
            }
            $n = $notas->get($a->id);
            return [
                'aluno_id'        => (int) $a->id,
                'nome'            => $a->nome_completo,
                'matricula'       => $a->matricula,
                'foto_url'        => FotoAlunoService::url($a->foto_path),
                'frequencia'      => $linhas->count() ? round($pres / $linhas->count() * 100, 1) : null,
                'faltas'          => $linhas->count() - $pres,
                'faltas_seguidas' => $seq,
                'media'           => $n ? round((float) $n->media, 1) : null,
                'notas'           => $n ? (int) $n->qtd : 0,
            ];
        })->values()->all();

        $listaAval = $avaliacoes->map(fn ($a) => [
            'avaliacao_id' => (int) $a->id,
            'data'         => substr((string) $a->data_aplicacao, 0, 10),
            'futura'       => Carbon::parse($a->data_aplicacao)->gt($hoje),
            'tipo'         => $a->nome_tipo,
            'cor'          => $a->cor,
            'disciplina'   => $a->nome_disciplina,
            'observacao'   => $a->descricao ?: null,
            'lancadas'     => (int) ($lancadas[$a->id] ?? 0),
            'total'        => count($ids),
            'query'        => [
                'turma_id' => (int) $t->id, 'nome_turma' => $t->nome_turma, 'avaliacao_id' => (int) $a->id,
                'disciplina_id' => (int) $a->disciplina_id, 'professor_id' => (int) $a->professor_id,
                'origem_turma_id' => (int) $t->id, 'origem_nome_turma' => $t->nome_turma,
            ],
        ])->values()->all();

        return [
            'turma_id'    => (int) $t->id,
            'encerrada'   => $encerrada,
            'meta_frequencia' => self::META_FREQUENCIA,
            'alunos'      => $listaAlunos,
            'avaliacoes'  => $listaAval,
            'disciplinas' => $disciplinas->map(fn ($d) => [
                'disciplina_id' => (int) $d->disciplina_id, 'disciplina' => $d->nome_disciplina,
                'professor' => $d->professor, 'minha' => $professorId !== null && (int) $d->professor_id === $professorId,
            ])->values()->all(),
        ];
    }

    /** O professor leciona nesta turma? */
    public function leciona(int $professorId, int $turmaId): bool
    {
        return DB::table('turma_professor_disciplina')->where('professor_id', $professorId)->where('turma_id', $turmaId)->exists();
    }

    // ── Auxiliares ─────────────────────────────────────────────────────────

    private function inicioAno(int $ano, Carbon $hoje): Carbon
    {
        $periodo = app(PeriodoLetivoService::class)->periodo($ano, $hoje);
        return !empty($periodo['inicio_ano']) ? Carbon::parse($periodo['inicio_ano']) : Carbon::create($ano, 2, 1);
    }

    /** @return int[] alunos ativos vinculados à turma */
    private function rosterAtivo(int $turmaId): array
    {
        return DB::table('turma_aluno as ta')
            ->join('alunos as a', 'a.id', '=', 'ta.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('ta.turma_id', $turmaId)->where('u.ativo', 1)
            ->pluck('ta.aluno_id')->map(fn ($id) => (int) $id)->all();
    }

    private function disciplinasDaTurma(int $turmaId, ?int $professorId): Collection
    {
        return DB::table('turma_professor_disciplina as tpd')
            ->join('disciplinas as d', 'd.id', '=', 'tpd.disciplina_id')
            ->leftJoin('professores as p', 'p.id', '=', 'tpd.professor_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'p.usuario_id')
            ->where('tpd.turma_id', $turmaId)
            ->when($professorId, fn ($q) => $q->where('tpd.professor_id', $professorId))
            ->orderBy('d.nome_disciplina')
            ->get(['tpd.disciplina_id', 'tpd.professor_id', 'd.nome_disciplina', 'u.nome_completo as professor']);
    }

    private function avaliacoes(int $turmaId, int $ano, array $disciplinaIds): Collection
    {
        if (!$disciplinaIds) {
            return collect();
        }
        return DB::table('avaliacoes as a')
            ->join('tipos_avaliacao as ta', 'ta.id', '=', 'a.tipo_avaliacao_id')
            ->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
            ->leftJoin('unidades_letivas as u', 'u.id', '=', 'a.unidade_letiva_id')
            ->where('a.turma_id', $turmaId)
            ->whereIn('a.disciplina_id', $disciplinaIds)
            ->where('u.ano_letivo', $ano)
            ->orderBy('a.data_aplicacao')
            ->get(['a.id', 'a.data_aplicacao', 'a.descricao', 'a.disciplina_id', 'a.professor_id', 'ta.nome_tipo', 'ta.cor', 'd.nome_disciplina']);
    }

    /** @return array<int,int> notas lançadas por avaliação (só alunos do roster) */
    private function notasLancadas(Collection $avaliacoes, array $roster): array
    {
        if ($avaliacoes->isEmpty() || !$roster) {
            return [];
        }
        return DB::table('notas')->whereIn('avaliacao_id', $avaliacoes->pluck('id'))->whereIn('aluno_id', $roster)
            ->select('avaliacao_id', DB::raw('COUNT(*) AS total'))->groupBy('avaliacao_id')
            ->pluck('total', 'avaliacao_id')->map(fn ($v) => (int) $v)->all();
    }
}
