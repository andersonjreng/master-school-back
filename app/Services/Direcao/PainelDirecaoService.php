<?php

namespace App\Services\Direcao;

use App\Services\Financeiro\ContratoFinanceiroService;
use App\Services\Financeiro\FinanceiroConfigService;
use App\Services\Turmas\PeriodoLetivoService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Painel da Direção (home da gestão, permissão painel.direcao): o dia de hoje, os
 * indicadores da escola com variação e série curta, alertas acionáveis e o
 * detalhamento de cada indicador (drill-down).
 *
 * Base: turmas ATIVAS do ano letivo e alunos com usuário ativo vinculados a
 * elas (turma_aluno).
 */
class PainelDirecaoService
{
    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    public const META_FREQUENCIA = 75;

    private ?array $rosterCache = null;

    public function __construct(
        private PeriodoLetivoService $periodo,
        private FinanceiroConfigService $config,
        private ContratoFinanceiroService $contratos,
    ) {
    }

    public function gerar(int $ano, ?Carbon $hoje = null): array
    {
        $hoje = $hoje ?? now();
        $roster = $this->roster($ano);
        $doDia = $this->hoje($ano, $roster, $hoje);

        return [
            'ano'     => $ano,
            'data'    => $hoje->toDateString(),
            'periodo' => $this->periodo->periodo($ano, $hoje),
            'hoje'    => $doDia,
            'kpis'    => $this->kpis($ano, $roster, $hoje),
            'alertas' => $this->alertas($ano, $roster, $hoje, $doDia),
        ];
    }

    // ── Base ─────────────────────────────────────────────────────────────────

    /** @return array{turmas: Collection, alunos: array<int, array{turma_id:int, turma:string}>} */
    private function roster(int $ano): array
    {
        if ($this->rosterCache) {
            return $this->rosterCache;
        }
        $turmas = DB::table('turmas')->where('ano_letivo', $ano)->where('status', 'Ativa')
            ->orderBy('nome_turma')->get(['id', 'nome_turma', 'capacidade', 'turno']);

        $alunos = [];
        DB::table('turma_aluno as ta')
            ->join('alunos as a', 'a.id', '=', 'ta.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->whereIn('ta.turma_id', $turmas->pluck('id'))
            ->where('u.ativo', 1)
            ->get(['ta.aluno_id', 'ta.turma_id', 'u.nome_completo'])
            ->each(function ($r) use (&$alunos, $turmas) {
                $alunos[(int) $r->aluno_id] = [
                    'turma_id' => (int) $r->turma_id,
                    'turma'    => $turmas->firstWhere('id', $r->turma_id)->nome_turma ?? '',
                    'nome'     => $r->nome_completo,
                ];
            });

        return $this->rosterCache = ['turmas' => $turmas, 'alunos' => $alunos];
    }

    private function idsAlunos(array $roster): array
    {
        return array_keys($roster['alunos']);
    }

    private function pctFrequencia(array $alunoIds, string $de, string $ate): ?float
    {
        if (!$alunoIds) {
            return null;
        }
        $r = DB::table('frequencia')->whereIn('aluno_id', $alunoIds)->whereBetween('data', [$de, $ate])
            ->selectRaw('SUM(presente = 1) AS p, COUNT(*) AS t')->first();
        return (int) $r->t > 0 ? round($r->p / $r->t * 100, 1) : null;
    }

    private function delta(?float $atual, ?float $anterior, int $casas = 1): ?float
    {
        return $atual === null || $anterior === null ? null : round($atual - $anterior, $casas);
    }

    // ── Hoje ─────────────────────────────────────────────────────────────────

    private function hoje(int $ano, array $roster, Carbon $hoje): array
    {
        $data = $hoje->toDateString();
        $ids = $this->idsAlunos($roster);
        $calendario = app(\App\Services\Turmas\CalendarioEscolarService::class);
        $diaLetivo = $calendario->ehLetivo($data, $calendario->mapa($ano));

        // Chamada do dia: uma turma "lançou" quando tem frequência de algum aluno dela hoje.
        $freqHoje = $ids ? DB::table('frequencia')->whereIn('aluno_id', $ids)->where('data', $data)->get(['aluno_id', 'presente']) : collect();
        $turmasLancadas = $freqHoje->map(fn ($f) => $roster['alunos'][(int) $f->aluno_id]['turma_id'] ?? null)->filter()->unique()->values()->all();
        $turmasComAlunos = collect($roster['alunos'])->pluck('turma_id')->unique();

        $professoresPorTurma = DB::table('turma_professor_disciplina as tpd')
            ->join('professores as p', 'p.id', '=', 'tpd.professor_id')
            ->join('usuarios as u', 'u.id', '=', 'p.usuario_id')
            ->whereIn('tpd.turma_id', $roster['turmas']->pluck('id'))
            ->get(['tpd.turma_id', 'u.nome_completo'])
            ->groupBy('turma_id')
            ->map(fn ($g) => $g->pluck('nome_completo')->unique()->values()->all());

        $pendentes = $roster['turmas']
            ->filter(fn ($t) => $turmasComAlunos->contains($t->id) && !in_array($t->id, $turmasLancadas))
            ->map(fn ($t) => ['turma_id' => (int) $t->id, 'turma' => $t->nome_turma, 'professores' => $professoresPorTurma[$t->id] ?? []])
            ->values()->all();

        // Ausentes de hoje, com faltas seguidas (registros mais recentes).
        $ausentesIds = $freqHoje->where('presente', 0)->pluck('aluno_id')->map(fn ($i) => (int) $i)->all();
        $ausentes = [];
        if ($ausentesIds) {
            $recentes = DB::table('frequencia')->whereIn('aluno_id', $ausentesIds)->where('data', '<=', $data)
                ->where('data', '>=', $hoje->copy()->subDays(30)->toDateString())
                ->orderByDesc('data')->get(['aluno_id', 'presente'])->groupBy('aluno_id');
            foreach ($ausentesIds as $id) {
                $seq = 0;
                foreach ($recentes[$id] ?? [] as $r) {
                    if ((int) $r->presente === 1) {
                        break;
                    }
                    $seq++;
                }
                $ausentes[] = ['aluno_id' => $id, 'nome' => $roster['alunos'][$id]['nome'], 'turma' => $roster['alunos'][$id]['turma'], 'faltas_seguidas' => $seq];
            }
            usort($ausentes, fn ($a, $b) => $b['faltas_seguidas'] <=> $a['faltas_seguidas'] ?: strcmp($a['nome'], $b['nome']));
        }

        $registros = DB::table('registros_aula')->where('data', $data)->whereIn('turma_id', $roster['turmas']->pluck('id'))->count();

        $avaliacoes = DB::table('avaliacoes as a')
            ->join('turmas as t', 't.id', '=', 'a.turma_id')
            ->leftJoin('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
            ->leftJoin('tipos_avaliacao as ta', 'ta.id', '=', 'a.tipo_avaliacao_id')
            ->whereIn('a.turma_id', $roster['turmas']->pluck('id'))
            ->whereBetween('a.data_aplicacao', [$data, $hoje->copy()->addDays(7)->toDateString()])
            ->orderBy('a.data_aplicacao')
            ->get(['a.id', 'a.data_aplicacao', 't.nome_turma as turma', 'd.nome_disciplina as disciplina', 'ta.nome_tipo as tipo']);

        $recebidoHoje = DB::table('baixas_pagamento')->where('data_pagamento', $data)
            ->selectRaw('COUNT(*) AS qtd, COALESCE(SUM(valor_pago), 0) AS valor')->first();
        $vencendo = DB::table('parcelas')->where('status', 'pendente')
            ->whereBetween('data_vencimento', [$data, $hoje->copy()->addDays(7)->toDateString()])
            ->selectRaw('COUNT(*) AS qtd, COALESCE(SUM(valor_final), 0) AS valor')->first();

        return [
            'dia_letivo' => $diaLetivo,
            'chamadas'   => [
                'turmas'   => $turmasComAlunos->count(),
                'lancadas' => count($turmasLancadas),
                'pendentes' => $pendentes,
            ],
            'presenca' => [
                'registros'  => $freqHoje->count(),
                'presentes'  => $freqHoje->where('presente', 1)->count(),
                'ausentes'   => count($ausentesIds),
                'percentual' => $freqHoje->count() ? round($freqHoje->where('presente', 1)->count() / $freqHoje->count() * 100, 1) : null,
                'lista_ausentes' => array_slice($ausentes, 0, 50),
            ],
            'registros_aula' => $registros,
            'avaliacoes' => [
                'hoje'   => $avaliacoes->where('data_aplicacao', $data)->values(),
                'semana' => $avaliacoes->values(),
            ],
            'financeiro' => [
                'recebido_hoje' => ['qtd' => (int) $recebidoHoje->qtd, 'valor' => (float) $recebidoHoje->valor],
                'vencendo_7d'   => ['qtd' => (int) $vencendo->qtd, 'valor' => (float) $vencendo->valor],
            ],
            'aniversariantes' => $this->aniversariantes($roster, $hoje),
        ];
    }

    /** Hoje e próximos 7 dias: alunos (ativos) e professores. */
    private function aniversariantes(array $roster, Carbon $hoje): array
    {
        $dias = [];
        for ($i = 0; $i <= 7; $i++) {
            $dias[$hoje->copy()->addDays($i)->format('m-d')] = $i;
        }
        $lista = [];
        $ids = $this->idsAlunos($roster);
        if ($ids) {
            DB::table('alunos')->whereIn('id', $ids)->whereNotNull('data_nascimento')->where('data_nascimento', '!=', '0000-00-00')
                ->get(['id', 'data_nascimento'])->each(function ($a) use (&$lista, $dias, $roster, $hoje) {
                    $md = substr($a->data_nascimento, 5, 5);
                    if (isset($dias[$md])) {
                        $lista[] = ['tipo' => 'aluno', 'nome' => $roster['alunos'][(int) $a->id]['nome'], 'turma' => $roster['alunos'][(int) $a->id]['turma'],
                            'em_dias' => $dias[$md], 'idade' => (int) $hoje->copy()->addDays($dias[$md])->format('Y') - (int) substr($a->data_nascimento, 0, 4)];
                    }
                });
        }
        DB::table('professores as p')->join('usuarios as u', 'u.id', '=', 'p.usuario_id')->where('u.ativo', 1)->whereNotNull('p.data_nascimento')
            ->get(['u.nome_completo', 'p.data_nascimento'])->each(function ($p) use (&$lista, $dias) {
                $md = substr($p->data_nascimento, 5, 5);
                if (isset($dias[$md])) {
                    $lista[] = ['tipo' => 'professor', 'nome' => $p->nome_completo, 'turma' => null, 'em_dias' => $dias[$md], 'idade' => null];
                }
            });
        usort($lista, fn ($a, $b) => $a['em_dias'] <=> $b['em_dias'] ?: strcmp($a['nome'], $b['nome']));
        return $lista;
    }

    // ── Indicadores ──────────────────────────────────────────────────────────

    private function kpis(int $ano, array $roster, Carbon $hoje): array
    {
        $ids = $this->idsAlunos($roster);

        // Frequência: mês atual x anterior + 6 meses.
        $serieFreq = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $hoje->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $serieFreq[] = ['rotulo' => self::MESES[$m->month - 1], 'valor' => $this->pctFrequencia($ids, $m->toDateString(), $m->copy()->endOfMonth()->toDateString())];
        }
        $freqAtual = $this->pctFrequencia($ids, $hoje->copy()->startOfMonth()->toDateString(), $hoje->toDateString());
        $freqAnterior = $serieFreq[4]['valor'] ?? null;
        // Mês corrente ainda sem chamada (ex.: começo do mês): usa o último mês com dados.
        $freqRef = $freqAtual ?? collect($serieFreq)->whereNotNull('valor')->last()['valor'] ?? null;

        // Alunos: ativos, entradas e saídas nos últimos 30 dias.
        $desde = $hoje->copy()->subDays(30)->toDateString();
        $entradas = DB::table('matriculas')->where('ano_letivo', $ano)->where('origem', 'sistema')->where('data_inicio', '>=', $desde)
            ->whereIn('aluno_id', $ids ?: [0])->count();
        $saidas = DB::table('matriculas')->where('ano_letivo', $ano)->where('status', 'cancelada')->where('data_fim', '>=', $desde)->count();

        // Aproveitamento (notas lançadas, escala 0–10) por bimestre.
        $aprov = $this->aproveitamentoPorUnidade($ano, $ids);
        $aprovAtual = $aprov ? end($aprov)['valor'] : null;
        $aprovAnterior = count($aprov) > 1 ? $aprov[count($aprov) - 2]['valor'] : null;

        // Financeiro do mês.
        $iniMes = $hoje->copy()->startOfMonth()->toDateString();
        $fimMes = $hoje->copy()->endOfMonth()->toDateString();
        $recebidoMes = (float) DB::table('baixas_pagamento')->whereBetween('data_pagamento', [$iniMes, $fimMes])->sum('valor_pago');
        $previstoMes = (float) DB::table('parcelas')->whereBetween('data_vencimento', [$iniMes, $fimMes])->whereNotIn('status', ['cancelado', 'negociado'])->sum('valor_final');
        $serieReceita = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $hoje->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $serieReceita[] = ['rotulo' => self::MESES[$m->month - 1], 'valor' => round((float) DB::table('baixas_pagamento')
                ->whereBetween('data_pagamento', [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()])->sum('valor_pago'), 2)];
        }

        DB::statement("UPDATE parcelas SET status = 'vencido' WHERE status = 'pendente' AND data_vencimento < CURDATE()");
        $vencido = DB::table('parcelas')->where('status', 'vencido')->selectRaw('COUNT(*) AS qtd, COALESCE(SUM(valor_final),0) AS valor, COUNT(DISTINCT aluno_id) AS alunos')->first();
        $baseInadimp = (float) DB::table('parcelas')->where('data_vencimento', '<', $hoje->toDateString())->whereNotIn('status', ['cancelado', 'negociado'])->sum('valor_final');

        $capacidade = (int) $roster['turmas']->sum('capacidade');

        return [
            'alunos' => [
                'valor' => count($ids), 'entradas_30d' => $entradas, 'saidas_30d' => $saidas,
                'turmas' => $roster['turmas']->count(),
            ],
            'frequencia' => [
                'valor' => $freqRef, 'mes_atual' => $freqAtual, 'anterior' => $freqAnterior,
                'delta' => $this->delta($freqAtual ?? $freqRef, $freqAnterior), 'meta' => self::META_FREQUENCIA, 'serie' => $serieFreq,
            ],
            'aproveitamento' => [
                'valor' => $aprovAtual, 'unidade' => $aprov ? end($aprov)['rotulo'] : null,
                'delta' => $this->delta($aprovAtual, $aprovAnterior), 'serie' => $aprov,
            ],
            'receita' => [
                'recebido' => round($recebidoMes, 2), 'previsto' => round($previstoMes, 2),
                'percentual' => $previstoMes > 0 ? round($recebidoMes / $previstoMes * 100, 1) : null, 'serie' => $serieReceita,
            ],
            'inadimplencia' => [
                'valor' => round((float) $vencido->valor, 2), 'parcelas' => (int) $vencido->qtd, 'alunos' => (int) $vencido->alunos,
                'percentual' => $baseInadimp > 0 ? round((float) $vencido->valor / $baseInadimp * 100, 1) : null,
            ],
            'ocupacao' => [
                'ocupadas' => count($ids), 'capacidade' => $capacidade,
                'percentual' => $capacidade > 0 ? round(count($ids) / $capacidade * 100, 1) : null,
            ],
        ];
    }

    /** Média das notas lançadas (normalizadas 0–10) por bimestre do ano. */
    private function aproveitamentoPorUnidade(int $ano, array $alunoIds, ?int $turmaId = null): array
    {
        if (!$alunoIds) {
            return [];
        }
        return DB::table('notas as n')
            ->join('avaliacoes as a', 'a.id', '=', 'n.avaliacao_id')
            ->join('unidades_letivas as ul', 'ul.id', '=', 'a.unidade_letiva_id')
            ->where('ul.ano_letivo', $ano)
            ->whereIn('n.aluno_id', $alunoIds)
            ->whereNotNull('n.nota_valor')->where('a.valor_maximo', '>', 0)
            ->when($turmaId, fn ($q) => $q->where('a.turma_id', $turmaId))
            ->groupBy('ul.id', 'ul.nome_unidade')->orderBy('ul.id')
            ->get(['ul.id', 'ul.nome_unidade', DB::raw('AVG(n.nota_valor / a.valor_maximo * 10) AS media')])
            ->map(fn ($r) => ['unidade_id' => (int) $r->id, 'rotulo' => $r->nome_unidade, 'valor' => round((float) $r->media, 1)])
            ->values()->all();
    }

    // ── Alertas ──────────────────────────────────────────────────────────────

    private function alertas(int $ano, array $roster, Carbon $hoje, array $h): array
    {
        $alertas = [];
        $add = function (string $nivel, string $icone, string $titulo, string $detalhe, string $link, ?array $query = null, ?string $pergunta = null) use (&$alertas) {
            $alertas[] = compact('nivel', 'icone', 'titulo', 'detalhe', 'link', 'query', 'pergunta');
        };

        if ($h['dia_letivo'] && (int) $hoje->format('H') >= 10 && $h['chamadas']['pendentes']) {
            $n = count($h['chamadas']['pendentes']);
            $add('atencao', 'pi pi-check-square', "$n turma" . ($n > 1 ? 's' : '') . ' sem chamada hoje',
                implode(', ', array_slice(array_column($h['chamadas']['pendentes'], 'turma'), 0, 4)) . ($n > 4 ? '…' : ''), '/frequencia');
        }

        $semProfessor = $roster['turmas']->filter(fn ($t) => !DB::table('turma_professor_disciplina')->where('turma_id', $t->id)->exists());
        if ($semProfessor->count()) {
            $add('critico', 'pi pi-user-minus', $semProfessor->count() . ' turma(s) sem professor', $semProfessor->pluck('nome_turma')->join(', '), '/turmas',
                null, 'Quais turmas estão sem professor e como isso afeta as aulas?');
        }

        // Avaliações aplicadas há mais de 7 dias sem nenhuma nota.
        $semNotas = DB::table('avaliacoes as a')->join('turmas as t', 't.id', '=', 'a.turma_id')
            ->whereIn('a.turma_id', $roster['turmas']->pluck('id'))
            ->where('a.data_aplicacao', '<', $hoje->copy()->subDays(7)->toDateString())
            ->whereNotExists(fn ($q) => $q->from('notas as n')->whereColumn('n.avaliacao_id', 'a.id'))
            ->count();
        if ($semNotas) {
            $add('atencao', 'pi pi-pencil', "$semNotas avaliação(ões) sem notas lançadas", 'Aplicadas há mais de 7 dias.', '/avaliacoes', null,
                'Quais avaliações estão sem notas lançadas e de quais professores?');
        }

        // Alunos com 3+ faltas seguidas (últimos 30 dias).
        $ids = $this->idsAlunos($roster);
        if ($ids) {
            $seguidas = 0;
            DB::table('frequencia')->whereIn('aluno_id', $ids)->where('data', '>=', $hoje->copy()->subDays(30)->toDateString())
                ->orderByDesc('data')->get(['aluno_id', 'presente'])->groupBy('aluno_id')
                ->each(function ($linhas) use (&$seguidas) {
                    $seq = 0;
                    foreach ($linhas as $l) {
                        if ((int) $l->presente === 1) {
                            break;
                        }
                        $seq++;
                    }
                    if ($seq >= 3) {
                        $seguidas++;
                    }
                });
            if ($seguidas) {
                $add('critico', 'pi pi-exclamation-circle', "$seguidas aluno(s) com 3+ faltas seguidas", 'Risco de evasão — vale contato com a família.',
                    '/frequencia/relatorio', null, 'Quais alunos estão com 3 ou mais faltas seguidas e de quais turmas?');
            }
        }

        $semContrato = $this->contratos->contarSemContrato($ano);
        if ($semContrato) {
            $add('atencao', 'pi pi-id-card', "$semContrato aluno(s) sem contrato em $ano", 'Não geram mensalidade.', '/financeiro/contratos',
                ['aba' => 'sem-contrato', 'ano' => $ano]);
        }
        $proximoAno = $ano + 1;
        if (in_array($proximoAno, $this->contratos->anosComTurmasAtivas(), true) && ($n = $this->contratos->contarSemContrato($proximoAno))) {
            $add('info', 'pi pi-calendar-plus', "$n aluno(s) sem contrato para $proximoAno", 'Rematrícula do próximo ano.', '/financeiro/contratos',
                ['aba' => 'sem-contrato', 'ano' => $proximoAno]);
        }

        $vencido = DB::table('parcelas')->where('status', 'vencido')->selectRaw('COUNT(*) AS q, COALESCE(SUM(valor_final),0) AS v')->first();
        if ((int) $vencido->q) {
            $add('atencao', 'pi pi-wallet', (int) $vencido->q . ' parcela(s) em atraso', 'R$ ' . number_format((float) $vencido->v, 2, ',', '.') . ' em aberto.',
                '/financeiro/cobrancas', ['aba' => 'inadimplencia'], 'Como está a inadimplência e quais turmas concentram os atrasos?');
        }

        if ($this->config->modoSicoob()) {
            $aguardando = DB::table('remessas_sicoob')->where('status', 'gerado')->count();
            if ($aguardando) {
                $add('atencao', 'pi pi-send', "$aguardando remessa(s) não enviada(s) ao banco", 'Boletos ainda não chegaram ao Sicoob.', '/financeiro/central-sicoob');
            }
            // Responsáveis financeiros sem dados para boleto.
            $incompletos = DB::table('aluno_responsavel as ar')->join('responsaveis as r', 'r.id', '=', 'ar.responsavel_id')
                ->whereIn('ar.aluno_id', $ids ?: [0])->where('ar.principal', 1)
                ->where(fn ($q) => $q->whereNull('r.endereco')->orWhere('r.endereco', '')->orWhereNull('r.cep')->orWhere('r.cep', '')->orWhereNull('r.numero'))
                ->distinct()->count('r.id');
            if ($incompletos) {
                $add('info', 'pi pi-address-book', "$incompletos responsável(is) financeiro(s) com cadastro incompleto", 'Sem endereço completo não há boleto.',
                    '/responsaveis');
            }
        }

        $ordem = ['critico' => 0, 'atencao' => 1, 'info' => 2];
        usort($alertas, fn ($a, $b) => $ordem[$a['nivel']] <=> $ordem[$b['nivel']]);
        return $alertas;
    }

    // ── Detalhamento (drill-down) ────────────────────────────────────────────

    public function detalhe(string $tipo, int $ano, array $filtros = [], ?Carbon $hoje = null): array
    {
        $hoje = $hoje ?? now();
        $roster = $this->roster($ano);
        return match ($tipo) {
            'frequencia'     => $this->detalheFrequencia($roster, $hoje, $filtros),
            'alunos'         => $this->detalheAlunos($ano, $roster, $hoje),
            'aproveitamento' => $this->detalheAproveitamento($ano, $roster),
            'receita'        => $this->detalheReceita($hoje),
            'inadimplencia'  => $this->detalheInadimplencia($roster),
            'ocupacao'       => $this->detalheOcupacao($roster),
            default          => throw new \InvalidArgumentException('Indicador desconhecido.'),
        };
    }

    /** Frequência do mês (ou do mês informado) por turma e alunos abaixo da meta. */
    private function detalheFrequencia(array $roster, Carbon $hoje, array $filtros): array
    {
        $mes = isset($filtros['mes']) && preg_match('/^\d{4}-\d{2}$/', $filtros['mes']) ? Carbon::parse($filtros['mes'] . '-01') : $hoje->copy()->startOfMonth();
        $ini = $mes->toDateString();
        $fim = min($mes->copy()->endOfMonth(), $hoje)->toDateString();
        $turmaId = isset($filtros['turma_id']) ? (int) $filtros['turma_id'] : null;

        $ids = collect($roster['alunos'])->filter(fn ($a) => !$turmaId || $a['turma_id'] === $turmaId)->keys()->all();
        $linhas = $ids ? DB::table('frequencia')->whereIn('aluno_id', $ids)->whereBetween('data', [$ini, $fim])->get(['aluno_id', 'data', 'presente']) : collect();

        $porTurma = [];
        foreach ($linhas->groupBy(fn ($l) => $roster['alunos'][(int) $l->aluno_id]['turma_id']) as $tid => $g) {
            $porTurma[] = ['turma_id' => (int) $tid, 'turma' => $roster['turmas']->firstWhere('id', $tid)->nome_turma ?? '',
                'percentual' => round($g->where('presente', 1)->count() / $g->count() * 100, 1), 'registros' => $g->count()];
        }
        usort($porTurma, fn ($a, $b) => $a['percentual'] <=> $b['percentual']);

        $alunos = [];
        foreach ($linhas->groupBy('aluno_id') as $aid => $g) {
            $pct = round($g->where('presente', 1)->count() / $g->count() * 100, 1);
            if ($pct < self::META_FREQUENCIA) {
                $alunos[] = ['aluno_id' => (int) $aid, 'nome' => $roster['alunos'][(int) $aid]['nome'], 'turma' => $roster['alunos'][(int) $aid]['turma'],
                    'percentual' => $pct, 'faltas' => $g->where('presente', 0)->count()];
            }
        }
        usort($alunos, fn ($a, $b) => $a['percentual'] <=> $b['percentual']);

        $diaria = $linhas->groupBy('data')->map(fn ($g, $d) => ['data' => $d, 'percentual' => round($g->where('presente', 1)->count() / $g->count() * 100, 1)])
            ->sortKeys()->values()->all();

        return [
            'mes' => $mes->format('Y-m'), 'turma_id' => $turmaId, 'meta' => self::META_FREQUENCIA,
            'percentual' => $linhas->count() ? round($linhas->where('presente', 1)->count() / $linhas->count() * 100, 1) : null,
            'por_turma' => $porTurma, 'diaria' => $diaria, 'alunos_abaixo_meta' => array_slice($alunos, 0, 30), 'total_abaixo_meta' => count($alunos),
        ];
    }

    private function detalheAlunos(int $ano, array $roster, Carbon $hoje): array
    {
        $porTurma = collect($roster['alunos'])->groupBy('turma_id')->map->count();
        $turmas = $roster['turmas']->map(fn ($t) => ['turma_id' => (int) $t->id, 'turma' => $t->nome_turma, 'turno' => $t->turno,
            'alunos' => (int) ($porTurma[$t->id] ?? 0), 'capacidade' => (int) $t->capacidade])->values();

        $mov = DB::table('matriculas as m')->join('alunos as a', 'a.id', '=', 'm.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->leftJoin('turmas as t', 't.id', '=', 'm.turma_id')
            ->where('m.ano_letivo', $ano)
            ->where(fn ($q) => $q->where(fn ($q2) => $q2->where('m.origem', 'sistema')->where('m.data_inicio', '>=', $hoje->copy()->subDays(60)->toDateString()))
                ->orWhere(fn ($q2) => $q2->where('m.status', 'cancelada')->where('m.data_fim', '>=', $hoje->copy()->subDays(60)->toDateString())))
            ->orderByDesc(DB::raw('COALESCE(m.data_fim, m.data_inicio)'))->limit(30)
            ->get(['u.nome_completo as nome', 't.nome_turma as turma', 'm.status', 'm.data_inicio', 'm.data_fim', 'm.motivo_encerramento']);

        return ['por_turma' => $turmas, 'movimentacoes' => $mov];
    }

    private function detalheAproveitamento(int $ano, array $roster): array
    {
        $ids = $this->idsAlunos($roster);
        $base = fn () => DB::table('notas as n')->join('avaliacoes as a', 'a.id', '=', 'n.avaliacao_id')
            ->join('unidades_letivas as ul', 'ul.id', '=', 'a.unidade_letiva_id')
            ->where('ul.ano_letivo', $ano)->whereIn('n.aluno_id', $ids ?: [0])
            ->whereNotNull('n.nota_valor')->where('a.valor_maximo', '>', 0);

        $porTurma = $base()->join('turmas as t', 't.id', '=', 'a.turma_id')->groupBy('t.id', 't.nome_turma')
            ->get(['t.id as turma_id', 't.nome_turma as turma', DB::raw('ROUND(AVG(n.nota_valor / a.valor_maximo * 10), 1) AS media')])
            ->sortBy('media')->values();
        $porDisciplina = $base()->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')->groupBy('d.id', 'd.nome_disciplina')
            ->get(['d.id as disciplina_id', 'd.nome_disciplina as disciplina', DB::raw('ROUND(AVG(n.nota_valor / a.valor_maximo * 10), 1) AS media')])
            ->sortBy('media')->values();
        // Mapa turma × disciplina (base do heatmap da Fase 2).
        $mapa = $base()->join('turmas as t', 't.id', '=', 'a.turma_id')->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
            ->groupBy('t.id', 't.nome_turma', 'd.id', 'd.nome_disciplina')
            ->get(['t.nome_turma as turma', 'd.nome_disciplina as disciplina', DB::raw('ROUND(AVG(n.nota_valor / a.valor_maximo * 10), 1) AS media')]);
        $alunos = $base()->groupBy('n.aluno_id')
            ->havingRaw('AVG(n.nota_valor / a.valor_maximo * 10) < 6')
            ->orderByRaw('AVG(n.nota_valor / a.valor_maximo * 10)')
            ->limit(30)
            ->get(['n.aluno_id', DB::raw('ROUND(AVG(n.nota_valor / a.valor_maximo * 10), 1) AS media'), DB::raw('COUNT(*) AS notas')])
            ->map(fn ($r) => ['aluno_id' => (int) $r->aluno_id, 'nome' => $roster['alunos'][(int) $r->aluno_id]['nome'] ?? '',
                'turma' => $roster['alunos'][(int) $r->aluno_id]['turma'] ?? '', 'media' => (float) $r->media, 'notas' => (int) $r->notas]);

        return ['serie' => $this->aproveitamentoPorUnidade($ano, $ids), 'por_turma' => $porTurma, 'por_disciplina' => $porDisciplina,
            'mapa' => $mapa, 'alunos_abaixo_6' => $alunos];
    }

    private function detalheReceita(Carbon $hoje): array
    {
        $ini = $hoje->copy()->startOfMonth()->toDateString();
        $fim = $hoje->copy()->endOfMonth()->toDateString();
        $porForma = DB::table('baixas_pagamento')->whereBetween('data_pagamento', [$ini, $fim])
            ->groupBy('forma_pagamento')->get(['forma_pagamento', DB::raw('COUNT(*) AS qtd'), DB::raw('SUM(valor_pago) AS valor')]);
        $ultimos = DB::table('baixas_pagamento as b')->join('parcelas as p', 'p.id', '=', 'b.parcela_id')
            ->join('alunos as a', 'a.id', '=', 'p.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->orderByDesc('b.data_pagamento')->orderByDesc('b.id')->limit(15)
            ->get(['u.nome_completo as aluno', 'p.numero_parcela', 'b.valor_pago', 'b.data_pagamento', 'b.forma_pagamento']);
        $aReceber = DB::table('parcelas')->where('status', 'pendente')
            ->whereBetween('data_vencimento', [$hoje->toDateString(), $hoje->copy()->addDays(30)->toDateString()])
            ->selectRaw('COUNT(*) AS qtd, COALESCE(SUM(valor_final),0) AS valor')->first();
        $serie = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $hoje->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $a = $m->toDateString();
            $b = $m->copy()->endOfMonth()->toDateString();
            $serie[] = [
                'rotulo'   => self::MESES[$m->month - 1] . '/' . $m->format('y'),
                'recebido' => round((float) DB::table('baixas_pagamento')->whereBetween('data_pagamento', [$a, $b])->sum('valor_pago'), 2),
                'previsto' => round((float) DB::table('parcelas')->whereBetween('data_vencimento', [$a, $b])->whereNotIn('status', ['cancelado', 'negociado'])->sum('valor_final'), 2),
            ];
        }
        return ['por_forma' => $porForma, 'ultimos' => $ultimos, 'a_receber_30d' => ['qtd' => (int) $aReceber->qtd, 'valor' => (float) $aReceber->valor], 'serie' => $serie];
    }

    private function detalheInadimplencia(array $roster): array
    {
        $vencidas = DB::table('parcelas as p')->join('alunos as a', 'a.id', '=', 'p.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('p.status', 'vencido')->get(['p.aluno_id', 'u.nome_completo', 'p.valor_final', 'p.data_vencimento']);
        // Quem não está numa turma ativa do ano (foi para o ano seguinte ou saiu): última turma vinculada.
        $foraDoAno = $vencidas->pluck('aluno_id')->unique()->filter(fn ($id) => !isset($roster['alunos'][(int) $id]))->values();
        $ultimaTurma = $foraDoAno->isEmpty() ? collect() : DB::table('matriculas as m')->join('turmas as t', 't.id', '=', 'm.turma_id')
            ->whereIn('m.aluno_id', $foraDoAno)->orderBy('m.id')->get(['m.aluno_id', 't.nome_turma', 't.ano_letivo'])
            ->keyBy('aluno_id')->map(fn ($r) => $r->nome_turma . ' (' . $r->ano_letivo . ')');
        $porAluno = $vencidas->groupBy('aluno_id')->map(fn ($g, $aid) => [
            'aluno_id' => (int) $aid, 'nome' => $g->first()->nome_completo,
            'turma' => $roster['alunos'][(int) $aid]['turma'] ?? $ultimaTurma[(int) $aid] ?? 'Sem turma',
            'parcelas' => $g->count(), 'valor' => round((float) $g->sum('valor_final'), 2), 'mais_antiga' => $g->min('data_vencimento'),
        ])->sortByDesc('valor')->values();
        $porTurma = $porAluno->groupBy('turma')->map(fn ($g, $t) => ['turma' => $t, 'alunos' => $g->count(), 'valor' => round($g->sum('valor'), 2)])
            ->sortByDesc('valor')->values();
        // Faixas de atraso.
        $faixas = ['até 30 dias' => 0, '31–60 dias' => 0, '61–90 dias' => 0, 'mais de 90' => 0];
        foreach ($vencidas as $v) {
            $d = (int) Carbon::parse($v->data_vencimento)->diffInDays(now(), true);
            $faixas[$d <= 30 ? 'até 30 dias' : ($d <= 60 ? '31–60 dias' : ($d <= 90 ? '61–90 dias' : 'mais de 90'))] += (float) $v->valor_final;
        }
        return ['por_turma' => $porTurma, 'por_aluno' => $porAluno->take(20)->values(),
            'faixas' => collect($faixas)->map(fn ($v, $k) => ['faixa' => $k, 'valor' => round($v, 2)])->values()];
    }

    private function detalheOcupacao(array $roster): array
    {
        $porTurma = collect($roster['alunos'])->groupBy('turma_id')->map->count();
        return ['por_turma' => $roster['turmas']->map(fn ($t) => [
            'turma_id' => (int) $t->id, 'turma' => $t->nome_turma, 'turno' => $t->turno, 'alunos' => (int) ($porTurma[$t->id] ?? 0),
            'capacidade' => (int) $t->capacidade, 'vagas' => max(0, (int) $t->capacidade - (int) ($porTurma[$t->id] ?? 0)),
        ])->sortByDesc(fn ($t) => $t['capacidade'] ? $t['alunos'] / $t['capacidade'] : 0)->values()];
    }
}
