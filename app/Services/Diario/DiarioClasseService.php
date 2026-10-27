<?php

namespace App\Services\Diario;

use App\Services\Documentos\EscolaConfigService;
use App\Services\Turmas\CalendarioEscolarService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Diário de Classe de uma turma num bimestre: frequência dia a dia, conteúdos
 * ministrados (registros de aula), avaliações e notas, e o checklist do que
 * falta para fechar o bimestre.
 *
 * - Dias letivos previstos vêm do calendário escolar (CalendarioEscolarService).
 * - Alunos: matrículas da turma que cruzam o bimestre (com entrada/saída),
 *   inclusive transferidos — o diário registra quem passou pela turma.
 * - Média bimestral: a mesma do boletim (Σ nota/valor_máx × peso ÷ 10, só
 *   avaliações com nota lançada).
 * - Professor vê conteúdos e notas das disciplinas dele; frequência é da turma.
 */
class DiarioClasseService
{
    private const META_FREQUENCIA = 75.0;

    public function __construct(
        private CalendarioEscolarService $calendario,
        private EscolaConfigService $escola,
    ) {}

    /** Turmas (e bimestres) para os filtros da tela. */
    public function opcoes(?int $professorId): array
    {
        $hoje = Carbon::today()->toDateString();
        $turmas = DB::table('turmas')
            ->when($professorId, fn ($q) => $q->whereIn('id',
                DB::table('turma_professor_disciplina')->where('professor_id', $professorId)->select('turma_id')))
            ->orderByDesc('ano_letivo')->orderBy('nome_turma')
            ->get(['id', 'nome_turma', 'ano_letivo', 'turno', 'status', 'sistema_avaliacao_id']);

        $unidadesPorAno = DB::table('unidades_letivas')->whereIn('ano_letivo', $turmas->pluck('ano_letivo')->unique())
            ->orderBy('id')->get(['id', 'nome_unidade', 'data_inicio', 'data_fim', 'ano_letivo'])->groupBy('ano_letivo');

        return $turmas->map(function ($t) use ($unidadesPorAno, $hoje) {
            $unidades = ($unidadesPorAno[$t->ano_letivo] ?? collect())->map(fn ($u) => [
                'id'     => (int) $u->id,
                'nome'   => $u->nome_unidade,
                'inicio' => $this->dataValida($u->data_inicio),
                'fim'    => $this->dataValida($u->data_fim),
            ])->values();
            // Bimestre "atual": o que contém hoje; senão o último já iniciado; senão o primeiro.
            $atual = $unidades->first(fn ($u) => $u['inicio'] && $u['inicio'] <= $hoje && $u['fim'] >= $hoje)
                ?? $unidades->filter(fn ($u) => $u['inicio'] && $u['inicio'] <= $hoje)->last()
                ?? $unidades->first();
            return [
                'turma_id'   => (int) $t->id,
                'nome_turma' => $t->nome_turma,
                'ano_letivo' => (int) $t->ano_letivo,
                'turno'      => $t->turno,
                'ativa'      => $t->status === 'Ativa',
                'bncc'       => (int) $t->sistema_avaliacao_id === 3,
                'unidades'   => $unidades->all(),
                'unidade_atual' => $atual['id'] ?? null,
            ];
        })->values()->all();
    }

    /** @return array|string diário, ou mensagem de erro (string) */
    public function gerar(int $turmaId, int $unidadeId, ?int $professorId, ?int $disciplinaId = null): array|string
    {
        $turma = DB::table('turmas')->where('id', $turmaId)->first();
        if (!$turma) {
            return 'Turma não encontrada.';
        }
        $unidade = DB::table('unidades_letivas')->where('id', $unidadeId)->first();
        if (!$unidade || (int) $unidade->ano_letivo !== (int) $turma->ano_letivo) {
            return 'Bimestre não encontrado para o ano letivo da turma.';
        }
        $ini = $this->dataValida($unidade->data_inicio);
        $fim = $this->dataValida($unidade->data_fim);
        if (!$ini || !$fim) {
            return "O {$unidade->nome_unidade} de {$turma->ano_letivo} ainda não tem datas. Defina em Parametrizações → Ano Letivo.";
        }

        $hoje = Carbon::today();
        $ate = min($fim, $hoje->toDateString());
        $periodoEncerrado = $fim < $hoje->toDateString();
        $ano = (int) $turma->ano_letivo;
        $mapa = $this->calendario->mapa($ano);
        $bncc = (int) $turma->sistema_avaliacao_id === 3;

        // Disciplinas: do professor (se for professor) e/ou a filtrada.
        $vinculos = DB::table('turma_professor_disciplina as tpd')
            ->join('disciplinas as d', 'd.id', '=', 'tpd.disciplina_id')
            ->leftJoin('professores as p', 'p.id', '=', 'tpd.professor_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'p.usuario_id')
            ->where('tpd.turma_id', $turmaId)
            ->orderBy('d.nome_disciplina')
            ->get(['tpd.disciplina_id', 'tpd.professor_id', 'd.nome_disciplina', 'u.nome_completo as professor']);
        $disciplinas = $vinculos
            ->when($professorId, fn ($c) => $c->where('professor_id', $professorId))
            ->when($disciplinaId, fn ($c) => $c->where('disciplina_id', $disciplinaId))
            ->unique('disciplina_id')->values();
        $discIds = $disciplinas->pluck('disciplina_id')->map(fn ($id) => (int) $id)->all();

        // ── Alunos (matrículas que cruzam o bimestre) ───────────────────────
        $alunos = $this->alunos($turmaId, $ano, $ini, $fim);
        $ids = array_keys($alunos);

        // ── Frequência ──────────────────────────────────────────────────────
        $registrosFreq = $ids ? DB::table('frequencia')->whereIn('aluno_id', $ids)
            ->whereBetween('data', [$ini, $fim])->get(['aluno_id', 'data', 'presente', 'justificativa']) : collect();
        $porDia = [];
        foreach ($registrosFreq as $r) {
            $dia = substr((string) $r->data, 0, 10);
            $aluno = $alunos[(int) $r->aluno_id] ?? null;
            if (!$aluno || !$this->matriculadoEm($aluno, $dia)) {
                continue; // registro de quando o aluno ainda não estava (ou já tinha saído) da turma
            }
            $porDia[$dia][(int) $r->aluno_id] = $r->presente ? 'P' : (trim((string) $r->justificativa) !== '' ? 'J' : 'F');
        }
        $datasChamada = array_keys($porDia);
        sort($datasChamada);

        $letivosPrevistos = $this->calendario->diasLetivos($mapa, Carbon::parse($ini), Carbon::parse($fim));
        $letivosAteHoje = array_values(array_filter($letivosPrevistos, fn ($d) => $d <= $ate));
        $colunas = array_values(array_unique(array_merge($letivosAteHoje, $datasChamada)));
        sort($colunas);

        $dias = array_map(fn ($d) => [
            'data'    => $d,
            'letivo'  => $this->calendario->ehLetivo($d, $mapa),
            'chamada' => isset($porDia[$d]),
            'motivo'  => $this->calendario->ehLetivo($d, $mapa) ? null : ($this->calendario->motivo($d, $mapa) ?? $this->rotuloStatus($this->calendario->classificar($d, $mapa))),
        ], $colunas);

        $linhas = [];
        foreach ($alunos as $id => $a) {
            $marcas = [];
            $p = $f = $j = 0;
            foreach ($colunas as $d) {
                if (!$this->matriculadoEm($a, $d)) {
                    $marcas[$d] = '-';
                    continue;
                }
                $m = $porDia[$d][$id] ?? null;
                $marcas[$d] = $m;
                if ($m === 'P') $p++;
                elseif ($m === 'F') $f++;
                elseif ($m === 'J') $j++;
            }
            $total = $p + $f + $j;
            $linhas[] = $a + [
                'marcas'      => $marcas,
                'presencas'   => $p,
                'faltas'      => $f + $j,
                'justificadas' => $j,
                'percentual'  => $total ? round($p / $total * 100, 1) : null,
            ];
        }

        // ── Conteúdos ministrados ──────────────────────────────────────────
        $registros = DB::table('registros_aula as r')
            ->leftJoin('professores as p', 'p.id', '=', 'r.professor_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'p.usuario_id')
            ->where('r.turma_id', $turmaId)->whereBetween('r.data', [$ini, $fim])
            ->when($discIds, fn ($q) => $q->whereIn('r.disciplina_id', $discIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('r.data')->orderBy('r.disciplina')
            ->get(['r.id', 'r.data', 'r.disciplina_id', 'r.disciplina', 'r.conteudo', 'r.numero_aulas', 'r.observacoes', 'u.nome_completo as professor'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'data' => substr((string) $r->data, 0, 10), 'disciplina_id' => (int) $r->disciplina_id,
                'disciplina' => $r->disciplina, 'conteudo' => $r->conteudo, 'numero_aulas' => (int) $r->numero_aulas,
                'observacoes' => $r->observacoes, 'professor' => $r->professor,
            ]);
        $diasComRegistro = $registros->pluck('data')->unique()->flip()->all();
        $cargaHoraria = $disciplinas->map(fn ($d) => [
            'disciplina_id' => (int) $d->disciplina_id,
            'disciplina'    => $d->nome_disciplina,
            'professor'     => $d->professor,
            'aulas'         => (int) $registros->where('disciplina_id', (int) $d->disciplina_id)->sum('numero_aulas'),
            'registros'     => $registros->where('disciplina_id', (int) $d->disciplina_id)->count(),
        ])->values()->all();

        // ── Avaliações e notas ─────────────────────────────────────────────
        [$avaliacoes, $notas, $medias] = $bncc || !$discIds ? [[], [], []] : $this->avaliacoesENotas($turmaId, $unidadeId, $discIds, $alunos);

        // ── Planejado × realizado ──────────────────────────────────────────
        $planejamento = $this->planejadoRealizado($turmaId, $ini, $fim, $disciplinas, $registros, $hoje->toDateString());

        // ── Pendências ─────────────────────────────────────────────────────
        $ontem = $hoje->copy()->subDay()->toDateString();
        $semChamada = array_values(array_filter($letivosAteHoje, fn ($d) => $d <= $ontem && !isset($porDia[$d])));
        $semRegistro = $discIds ? array_values(array_filter($letivosAteHoje, fn ($d) => $d <= $ontem && !isset($diasComRegistro[$d]))) : [];
        $naoLetivo = array_values(array_filter($datasChamada, fn ($d) => !$this->calendario->ehLetivo($d, $mapa)));
        $ativos = count(array_filter($alunos, fn ($a) => $this->matriculadoEm($a, $ate)));
        $notasIncompletas = array_values(array_filter($avaliacoes, fn ($a) => $a['data'] <= $hoje->toDateString() && $a['lancadas'] < $a['esperadas']));
        $baixaFrequencia = array_values(array_filter($linhas, fn ($l) => $l['percentual'] !== null && $l['percentual'] < self::META_FREQUENCIA));

        $totalPendencias = count($semChamada) + count($semRegistro) + count($notasIncompletas) + count($naoLetivo);
        $status = !$periodoEncerrado ? 'em_andamento' : ($totalPendencias ? 'pendente' : 'pronto');

        $presencasTotal = array_sum(array_column($linhas, 'presencas'));
        $marcadosTotal = $presencasTotal + array_sum(array_column($linhas, 'faltas'));

        return [
            'escola' => $this->cabecalhoEscola(),
            'turma' => [
                'id' => (int) $turma->id, 'nome' => $turma->nome_turma, 'ano_letivo' => $ano, 'turno' => $turma->turno,
                'sala' => $turma->sala ?? null, 'etapa' => $turma->etapa_bncc ?? null, 'bncc' => $bncc,
                'status' => $turma->status,
            ],
            'unidade' => ['id' => (int) $unidade->id, 'nome' => $unidade->nome_unidade, 'inicio' => $ini, 'fim' => $fim, 'encerrado' => $periodoEncerrado],
            'professores' => $vinculos->pluck('professor')->filter()->unique()->values()->all(),
            'disciplinas' => $cargaHoraria,
            'resumo' => [
                'dias_letivos_previstos' => count($letivosPrevistos),
                'dias_letivos_ate_hoje'  => count($letivosAteHoje),
                'dias_com_chamada'       => count(array_intersect($datasChamada, $letivosPrevistos)),
                'dias_com_registro'      => count(array_intersect(array_keys($diasComRegistro), $letivosPrevistos)),
                'aulas_registradas'      => (int) $registros->sum('numero_aulas'),
                'frequencia_media'       => $marcadosTotal ? round($presencasTotal / $marcadosTotal * 100, 1) : null,
                'alunos'                 => count($alunos),
                'alunos_ativos'          => $ativos,
                'avaliacoes'             => count($avaliacoes),
                'avaliacoes_completas'   => count(array_filter($avaliacoes, fn ($a) => $a['lancadas'] >= $a['esperadas'])),
                'meta_frequencia'        => self::META_FREQUENCIA,
                'media_minima'           => app(\App\Services\Avaliacoes\MediaBimestralService::class)->mediaMinima($turmaId),
            ],
            'frequencia' => ['dias' => $dias, 'alunos' => $linhas],
            'registros' => $registros->values()->all(),
            'planejamento' => $planejamento,
            'avaliacoes' => $avaliacoes,
            // Objetos JSON mesmo vazios (chaves = ids de aluno / avaliação / disciplina).
            'notas' => (object) array_map(fn ($n) => (object) $n, $notas),
            'medias' => (object) array_map(fn ($m) => (object) $m, $medias),
            'pendencias' => [
                'status'               => $status,
                'total'                => $totalPendencias,
                'sem_chamada'          => $semChamada,
                'sem_registro'         => $semRegistro,
                'notas_incompletas'    => $notasIncompletas,
                'chamada_nao_letivo'   => array_map(fn ($d) => ['data' => $d, 'motivo' => $this->calendario->motivo($d, $mapa) ?? $this->rotuloStatus($this->calendario->classificar($d, $mapa))], $naoLetivo),
                'baixa_frequencia'     => array_map(fn ($l) => ['aluno_id' => $l['aluno_id'], 'nome' => $l['nome'], 'percentual' => $l['percentual'], 'faltas' => $l['faltas']], $baixaFrequencia),
            ],
            'gerado_em' => now()->toDateTimeString(),
        ];
    }

    /** O professor leciona na turma? */
    public function leciona(int $professorId, int $turmaId): bool
    {
        return DB::table('turma_professor_disciplina')->where('professor_id', $professorId)->where('turma_id', $turmaId)->exists();
    }

    // ── Auxiliares ─────────────────────────────────────────────────────────

    /**
     * Alunos da turma no bimestre, por matrícula (entrada/saída). Sem
     * matrícula (dados antigos), cai no vínculo atual (turma_aluno).
     *
     * @return array<int, array{aluno_id: int, nome: string, matricula: ?string, entrada: ?string, saida: ?string, situacao: string}>
     */
    private function alunos(int $turmaId, int $ano, string $ini, string $fim): array
    {
        $mats = DB::table('matriculas as m')
            ->join('alunos as a', 'a.id', '=', 'm.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('m.turma_id', $turmaId)->where('m.ano_letivo', $ano)
            ->where(fn ($q) => $q->whereNull('m.data_inicio')->orWhere('m.data_inicio', '<=', $fim))
            ->where(fn ($q) => $q->whereNull('m.data_fim')->orWhere('m.data_fim', '>=', $ini))
            ->orderBy('u.nome_completo')
            // Número de matrícula daquele ano (o diário de um ano fechado não mostra o número do ano novo).
            ->get(['m.aluno_id', 'u.nome_completo', DB::raw(app(\App\Services\Matriculas\NumeroMatriculaService::class)->sqlNumeroDoAno() . ' as matricula'),
                'm.data_inicio', 'm.data_fim', 'm.status']);

        $alunos = [];
        foreach ($mats as $m) {
            $id = (int) $m->aluno_id;
            $entrada = $m->data_inicio ? substr($m->data_inicio, 0, 10) : null;
            $saida = $m->data_fim ? substr($m->data_fim, 0, 10) : null;
            if (isset($alunos[$id])) { // matrículas sobrepostas: une o intervalo
                $alunos[$id]['entrada'] = $alunos[$id]['entrada'] && $entrada ? min($alunos[$id]['entrada'], $entrada) : null;
                $alunos[$id]['saida'] = $alunos[$id]['saida'] && $saida ? max($alunos[$id]['saida'], $saida) : null;
                continue;
            }
            $alunos[$id] = [
                'aluno_id' => $id, 'nome' => $m->nome_completo, 'matricula' => $m->matricula,
                'entrada' => $entrada, 'saida' => $saida, 'situacao' => $m->status,
            ];
        }

        // Vínculo atual sem matrícula registrada
        $semMatricula = DB::table('turma_aluno as ta')
            ->join('alunos as a', 'a.id', '=', 'ta.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('ta.turma_id', $turmaId)->whereNotIn('ta.aluno_id', array_keys($alunos) ?: [0])
            ->get(['ta.aluno_id', 'u.nome_completo', 'a.matricula']);
        foreach ($semMatricula as $s) {
            $alunos[(int) $s->aluno_id] = [
                'aluno_id' => (int) $s->aluno_id, 'nome' => $s->nome_completo, 'matricula' => $s->matricula,
                'entrada' => null, 'saida' => null, 'situacao' => 'ativa',
            ];
        }

        uasort($alunos, fn ($a, $b) => strcoll(mb_strtolower($a['nome']), mb_strtolower($b['nome'])));
        // Entrada/saída só importam se caem dentro do bimestre.
        foreach ($alunos as &$a) {
            if ($a['entrada'] && $a['entrada'] <= $ini) $a['entrada'] = null;
            if ($a['saida'] && $a['saida'] >= $fim) $a['saida'] = null;
        }
        unset($a);
        return $alunos;
    }

    private function matriculadoEm(array $aluno, string $dia): bool
    {
        return (!$aluno['entrada'] || $dia >= $aluno['entrada']) && (!$aluno['saida'] || $dia <= $aluno['saida']);
    }

    /** @return array{0: array, 1: array, 2: array} avaliações, notas[aluno][avaliacao], médias[aluno][disciplina] */
    private function avaliacoesENotas(int $turmaId, int $unidadeId, array $discIds, array $alunos): array
    {
        $avs = DB::table('avaliacoes as av')
            ->join('tipos_avaliacao as ta', 'ta.id', '=', 'av.tipo_avaliacao_id')
            ->join('disciplinas as d', 'd.id', '=', 'av.disciplina_id')
            ->where('av.turma_id', $turmaId)->where('av.unidade_letiva_id', $unidadeId)
            ->whereIn('av.disciplina_id', $discIds)
            ->orderBy('d.nome_disciplina')->orderBy('av.data_aplicacao')->orderBy('av.id')
            ->get(['av.id', 'av.data_aplicacao', 'av.descricao', 'av.valor_maximo', 'av.disciplina_id', 'av.professor_id', 'd.nome_disciplina', 'ta.nome_tipo', 'ta.peso', 'ta.cor']);

        $ids = array_keys($alunos);
        $notasRows = $avs->isNotEmpty() && $ids
            ? DB::table('notas')->whereIn('avaliacao_id', $avs->pluck('id'))->whereIn('aluno_id', $ids)
                ->orderBy('id')->get(['avaliacao_id', 'aluno_id', 'nota_valor'])
            : collect();

        $notas = [];
        foreach ($notasRows as $n) {
            $notas[(int) $n->aluno_id][(int) $n->avaliacao_id] = $n->nota_valor !== null ? (float) $n->nota_valor : null;
        }

        $avaliacoes = [];
        foreach ($avs as $av) {
            $data = substr((string) $av->data_aplicacao, 0, 10);
            // Esperadas: alunos matriculados na data da avaliação.
            $esperadas = count(array_filter($alunos, fn ($a) => $this->matriculadoEm($a, $data)));
            $lancadas = count(array_filter($ids, fn ($id) => array_key_exists((int) $av->id, $notas[$id] ?? [])));
            $avaliacoes[] = [
                'avaliacao_id' => (int) $av->id, 'data' => $data, 'tipo' => $av->nome_tipo, 'cor' => $av->cor,
                'peso' => (int) $av->peso, 'valor_maximo' => (float) $av->valor_maximo, 'descricao' => $av->descricao ?: null,
                'disciplina_id' => (int) $av->disciplina_id, 'disciplina' => $av->nome_disciplina,
                'professor_id' => $av->professor_id ? (int) $av->professor_id : null,
                'lancadas' => min($lancadas, $esperadas), 'esperadas' => $esperadas,
            ];
        }

        // Média bimestral = boletim (MediaBimestralService).
        $regra = app(\App\Services\Avaliacoes\MediaBimestralService::class);
        $medias = [];
        foreach ($ids as $alunoId) {
            foreach (collect($avaliacoes)->groupBy('disciplina_id') as $discId => $doDisc) {
                $medias[$alunoId][(int) $discId] = $regra->media($doDisc->map(fn ($av) => [
                    'nota' => $notas[$alunoId][$av['avaliacao_id']] ?? null, 'valor_maximo' => $av['valor_maximo'], 'peso' => $av['peso'],
                ]));
            }
        }

        return [$avaliacoes, $notas, $medias];
    }

    /**
     * Planejado × realizado no bimestre, por disciplina:
     * - cumprida: plano marcado como dado, ou com registro de aula no mesmo dia/disciplina;
     * - atrasada: plano de dia que já passou, sem registro;
     * - futura: plano de hoje em diante;
     * - sem plano: registro de aula sem plano correspondente.
     * Também lista as habilidades BNCC dos planos cumpridos (o que foi trabalhado).
     */
    private function planejadoRealizado(int $turmaId, string $ini, string $fim, Collection $disciplinas, Collection $registros, string $hoje): array
    {
        $discIds = $disciplinas->pluck('disciplina_id')->map(fn ($id) => (int) $id)->all();
        $planos = $discIds ? DB::table('planos_aula')->where('turma_id', $turmaId)->whereBetween('data', [$ini, $fim])
            ->whereIn('disciplina_id', $discIds)->orderBy('data')->orderBy('id')
            ->get(['id', 'data', 'disciplina_id', 'tema', 'status', 'habilidades', 'registro_aula_id', 'gerado_max']) : collect();

        // Registros por dia+disciplina (para casar com os planos)
        $regPorChave = [];
        foreach ($registros as $r) {
            $regPorChave[$r['data'] . ':' . $r['disciplina_id']][] = $r;
        }

        $linha = [];
        $usados = [];
        $codigosCumpridos = [];
        foreach ($planos as $p) {
            $data = substr((string) $p->data, 0, 10);
            $chave = $data . ':' . (int) $p->disciplina_id;
            $reg = null;
            if ($p->registro_aula_id) {
                $reg = $registros->firstWhere('id', (int) $p->registro_aula_id);
            }
            $reg ??= collect($regPorChave[$chave] ?? [])->first(fn ($r) => !isset($usados[$r['id']]));
            if ($reg) {
                $usados[$reg['id']] = true;
            }
            $cumprida = $p->status === 'dada' || $reg !== null;
            $situacao = $cumprida ? 'cumprida' : ($data < $hoje ? 'atrasada' : ($data === $hoje ? 'hoje' : 'futura'));
            if ($cumprida) {
                foreach (json_decode((string) $p->habilidades, true) ?: [] as $c) {
                    $codigosCumpridos[(int) $p->disciplina_id][$c] = true;
                }
            }
            $linha[] = [
                'data' => $data, 'disciplina_id' => (int) $p->disciplina_id, 'situacao' => $situacao,
                'plano' => ['id' => (int) $p->id, 'tema' => $p->tema, 'gerado_max' => (bool) $p->gerado_max],
                'registro' => $reg ? ['id' => $reg['id'], 'conteudo' => mb_substr((string) $reg['conteudo'], 0, 160)] : null,
            ];
        }
        foreach ($registros as $r) {
            if (!isset($usados[$r['id']])) {
                $linha[] = [
                    'data' => $r['data'], 'disciplina_id' => $r['disciplina_id'], 'situacao' => 'sem_plano', 'plano' => null,
                    'registro' => ['id' => $r['id'], 'conteudo' => mb_substr((string) $r['conteudo'], 0, 160)],
                ];
            }
        }
        usort($linha, fn ($a, $b) => [$a['data'], $a['disciplina_id']] <=> [$b['data'], $b['disciplina_id']]);

        $todasHab = array_merge(...array_map('array_keys', $codigosCumpridos ?: [[]]));
        $textos = $todasHab ? DB::table('bncc_habilidades')->whereIn('codigo', $todasHab)->pluck('texto', 'codigo')->all() : [];

        $conta = fn (array $itens, string $sit) => count(array_filter($itens, fn ($l) => $l['situacao'] === $sit));
        $porDisciplina = $disciplinas->map(function ($d) use ($linha, $conta, $codigosCumpridos, $textos) {
            $id = (int) $d->disciplina_id;
            $itens = array_values(array_filter($linha, fn ($l) => $l['disciplina_id'] === $id));
            $planejadas = count(array_filter($itens, fn ($l) => $l['plano'] !== null));
            return [
                'disciplina_id' => $id,
                'disciplina' => $d->nome_disciplina,
                'planejadas' => $planejadas,
                'cumpridas' => $conta($itens, 'cumprida'),
                'atrasadas' => $conta($itens, 'atrasada'),
                'futuras' => $conta($itens, 'futura') + $conta($itens, 'hoje'),
                'sem_plano' => $conta($itens, 'sem_plano'),
                'habilidades' => array_map(fn ($c) => ['codigo' => $c, 'texto' => $textos[$c] ?? ''], array_keys($codigosCumpridos[$id] ?? [])),
            ];
        })->values()->all();

        $cumpridas = $conta($linha, 'cumprida');
        $atrasadas = $conta($linha, 'atrasada');
        $semPlano = $conta($linha, 'sem_plano');
        $dadas = $cumpridas + $semPlano; // aulas que aconteceram (com ou sem plano)

        return [
            'resumo' => [
                'planejadas' => $planos->count(),
                'cumpridas' => $cumpridas,
                'atrasadas' => $atrasadas,
                'futuras' => $conta($linha, 'futura') + $conta($linha, 'hoje'),
                'sem_plano' => $semPlano,
                'aulas_dadas' => $dadas,
                'pct_cumprido' => ($cumpridas + $atrasadas) ? round($cumpridas / ($cumpridas + $atrasadas) * 100) : null,
                'pct_planejadas' => $dadas ? round($cumpridas / $dadas * 100) : null,
                'habilidades_trabalhadas' => count($todasHab),
            ],
            'por_disciplina' => $porDisciplina,
            'linha' => $linha,
        ];
    }

    private function cabecalhoEscola(): array
    {
        $c = $this->escola->obter();
        $logo = (string) ($c['logo_url'] ?? '');
        if ($logo !== '' && !str_starts_with($logo, 'http')) {
            $logo = url('storage/' . ltrim($logo, '/'));
        }
        $c['logo_url'] = $logo;
        return [
            'nome' => $c['nome_escola'] ?? '', 'cnpj' => $c['cnpj'] ?? '', 'endereco' => $c['endereco'] ?? '',
            'autorizacao' => $c['autorizacao_mec'] ?? '', 'logo_url' => $c['logo_url'] ?? '',
            'diretor_nome' => $c['diretor_nome'] ?? '', 'diretor_cargo' => $c['diretor_cargo'] ?? '',
        ];
    }

    private function dataValida(?string $d): ?string
    {
        return $d && $d !== '0000-00-00' ? substr($d, 0, 10) : null;
    }

    private function rotuloStatus(string $s): string
    {
        return match ($s) {
            'feriado' => 'Feriado', 'ponto_facultativo' => 'Ponto facultativo', 'recesso' => 'Recesso', 'fim_de_semana' => 'Fim de semana', 'fora' => 'Fora do período letivo',
            default => 'Dia sem aula',
        };
    }
}
