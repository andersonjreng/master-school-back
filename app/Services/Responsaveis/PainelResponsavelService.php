<?php

namespace App\Services\Responsaveis;

use App\Http\Controllers\Api\AlunoController;
use App\Services\Turmas\PeriodoLetivoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Painel da família (home do responsável), num único payload — só com os
 * dependentes do próprio responsável.
 *
 * Notas: mesma conta do boletim (AlunoController::boletim) — por bimestre,
 * Σ (nota / valor máximo) × peso / 10, só com avaliações já lançadas. Como
 * isso é "pontos acumulados", também devolvemos quantos pontos já foram
 * avaliados e o aproveitamento (nota proporcional ao que já foi avaliado),
 * para uma nota parcial não parecer baixa.
 */
class PainelResponsavelService
{
    private const META_FREQUENCIA = 75.0;

    public function __construct(private PeriodoLetivoService $periodos)
    {
    }

    public function gerar(int $usuarioId): array
    {
        $hoje = Carbon::today();
        $dependentes = DB::table('responsaveis as r')
            ->join('aluno_responsavel as ar', 'ar.responsavel_id', '=', 'r.id')
            ->join('alunos as a', 'a.id', '=', 'ar.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('r.usuario_id', $usuarioId)
            ->orderBy('u.nome_completo')
            ->get(['a.id as aluno_id', 'u.nome_completo as nome', 'u.ativo', 'a.matricula', 'a.foto_path', 'ar.parentesco']);

        $cards = [];
        foreach ($dependentes as $dep) {
            $cards[] = $this->dependente($dep, $hoje);
        }

        return [
            'hoje'        => $hoje->toDateString(),
            'dependentes' => $cards,
            'financeiro'  => $this->financeiro($dependentes->pluck('aluno_id')->map(fn ($id) => (int) $id)->all(), $hoje),
        ];
    }

    private function dependente(object $dep, Carbon $hoje): array
    {
        $alunoId = (int) $dep->aluno_id;
        $base = [
            'aluno_id'   => $alunoId,
            'nome'       => $dep->nome,
            'matricula'  => $dep->matricula,
            'parentesco' => $dep->parentesco,
            'foto_url'   => \App\Services\Alunos\FotoAlunoService::url($dep->foto_path),
            'ativo'      => (bool) $dep->ativo,
        ];

        // Turma atual (vínculo vigente); sem turma = aluno fora de turma ativa.
        $turma = DB::table('turma_aluno as ta')
            ->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->leftJoin('sistemas_avaliacao as s', 's.id', '=', 't.sistema_avaliacao_id')
            ->where('ta.aluno_id', $alunoId)
            ->orderByDesc('t.ano_letivo')
            ->first(['t.id', 't.nome_turma', 't.ano_letivo', 't.status', 't.sistema_avaliacao_id', 's.media_minima']);

        if (!$turma) {
            return $base + ['turma' => null, 'situacao' => ['nivel' => 'neutro', 'motivos' => ['Sem turma ativa no momento']]];
        }

        $ano = (int) $turma->ano_letivo;
        $periodo = $this->periodos->periodo($ano, $hoje);
        $bncc = (int) $turma->sistema_avaliacao_id === 3;
        $mediaMinima = $turma->media_minima !== null ? (float) $turma->media_minima : 6.0;

        $frequencia = $this->frequencia($alunoId, $ano, $periodo, $hoje);
        $notas = $bncc ? null : $this->notas($alunoId, (int) $turma->id, $ano, $periodo);
        $proximas = $this->proximas((int) $turma->id, $hoje);

        // Situação geral (sempre com o motivo por escrito, nunca só a cor).
        $motivos = [];
        if ($frequencia['percentual'] !== null && $frequencia['percentual'] < self::META_FREQUENCIA) {
            $motivos[] = 'Frequência abaixo de 75%';
        }
        if ($frequencia['faltas_seguidas'] >= 3) {
            $motivos[] = "{$frequencia['faltas_seguidas']} faltas seguidas";
        }
        if ($notas && $notas['aproveitamento'] !== null && $notas['aproveitamento'] < $mediaMinima) {
            $motivos[] = 'Aproveitamento abaixo da média';
        }
        $baixas = $notas ? collect($notas['disciplinas'])->filter(fn ($d) => $d['aproveitamento'] !== null && $d['aproveitamento'] < $mediaMinima)->count() : 0;
        if ($baixas && !in_array('Aproveitamento abaixo da média', $motivos, true)) {
            $motivos[] = "$baixas disciplina(s) abaixo da média";
        }

        // Sem chamada nem nota ainda (ex.: ano que acabou de começar): não dá
        // para dizer que está "tudo em dia".
        $semRegistros = $frequencia['dias'] === 0
            && !collect($notas['disciplinas'] ?? [])->sum('lancadas');

        return $base + [
            'turma'        => ['id' => (int) $turma->id, 'nome' => $turma->nome_turma, 'ano_letivo' => $ano, 'bncc' => $bncc],
            'periodo'      => $periodo,
            'media_minima' => $mediaMinima,
            'frequencia'   => $frequencia,
            'notas'        => $notas,
            'proximas_avaliacoes' => $proximas,
            'situacao'     => $semRegistros
                ? ['nivel' => 'neutro', 'motivos' => ["Ano letivo $ano ainda sem registros"]]
                : ['nivel' => $motivos ? 'atencao' : 'ok', 'motivos' => $motivos],
        ];
    }

    /** Frequência do ano letivo, faixa das últimas aulas e faltas recentes. */
    private function frequencia(int $alunoId, int $ano, array $periodo, Carbon $hoje): array
    {
        $inicio = $periodo['inicio_ano'] ?? "$ano-01-01";
        $registros = DB::table('frequencia')
            ->where('aluno_id', $alunoId)
            ->whereBetween('data', [$inicio, min($hoje->toDateString(), "$ano-12-31")])
            ->orderBy('data')
            ->get(['data', 'presente', 'justificativa']);

        $total = $registros->count();
        $presencas = $registros->where('presente', 1)->count();
        $faltas = $registros->filter(fn ($r) => !$r->presente);
        $justificadas = $faltas->filter(fn ($r) => trim((string) $r->justificativa) !== '')->count();

        $seq = 0;
        foreach ($registros->reverse() as $r) {
            if ($r->presente) {
                break;
            }
            $seq++;
        }

        return [
            'percentual'      => $total ? round($presencas / $total * 100, 1) : null,
            'presencas'       => $presencas,
            'faltas'          => $total - $presencas,
            'justificadas'    => $justificadas,
            'dias'            => $total,
            'faltas_seguidas' => $seq,
            'ultimas'         => $registros->slice(-20)->map(fn ($r) => [
                'data'   => substr((string) $r->data, 0, 10),
                'status' => $r->presente ? 'P' : (trim((string) $r->justificativa) !== '' ? 'J' : 'F'),
            ])->values(),
            'faltas_recentes' => $faltas->slice(-5)->reverse()->map(fn ($r) => [
                'data'          => substr((string) $r->data, 0, 10),
                'justificativa' => trim((string) $r->justificativa) ?: null,
            ])->values(),
        ];
    }

    /**
     * Notas do bimestre de referência (o atual; sem datas cadastradas, o
     * último bimestre com nota lançada) + variação vs. o bimestre anterior.
     */
    private function notas(int $alunoId, int $turmaId, int $ano, array $periodo): array
    {
        $unidades = DB::table('unidades_letivas')->where('ano_letivo', $ano)->orderBy('id')->get(['id', 'nome_unidade']);
        $refId = $periodo['unidade_id'] ?? null;
        if (!$refId || $periodo['situacao'] !== 'em_andamento') {
            $refId = DB::table('notas as n')
                ->join('avaliacoes as a', 'a.id', '=', 'n.avaliacao_id')
                ->where('n.aluno_id', $alunoId)->where('a.turma_id', $turmaId)
                ->whereIn('a.unidade_letiva_id', $unidades->pluck('id'))
                ->max('a.unidade_letiva_id') ?? $refId ?? $unidades->first()?->id;
        }
        if (!$refId) {
            return ['bimestre' => null, 'disciplinas' => [], 'aproveitamento' => null, 'media_acumulada' => null];
        }

        $idx = $unidades->search(fn ($u) => (int) $u->id === (int) $refId);
        $anteriorId = $idx > 0 ? $unidades[$idx - 1]->id : null;

        $atual = $this->boletimUnidade($alunoId, $turmaId, (int) $refId);
        $anterior = $anteriorId ? $this->boletimUnidade($alunoId, $turmaId, (int) $anteriorId) : [];

        $disciplinas = [];
        foreach ($atual as $discId => $d) {
            $ant = $anterior[$discId]['aproveitamento'] ?? null;
            $disciplinas[] = $d + [
                'variacao' => ($d['aproveitamento'] !== null && $ant !== null) ? round($d['aproveitamento'] - $ant, 1) : null,
            ];
        }
        usort($disciplinas, fn ($a, $b) => strcmp($a['nome'], $b['nome']));

        $comNota = array_filter($disciplinas, fn ($d) => $d['aproveitamento'] !== null);
        return [
            'bimestre'        => $unidades[$idx]->nome_unidade ?? null,
            'unidade_id'      => (int) $refId,
            'disciplinas'     => $disciplinas,
            'aproveitamento'  => $comNota ? round(array_sum(array_column($comNota, 'aproveitamento')) / count($comNota), 1) : null,
            'media_acumulada' => $comNota ? round(array_sum(array_column($comNota, 'media')) / count($comNota), 2) : null,
        ];
    }

    /** @return array<int, array> por disciplina_id — mesma conta do boletim. */
    private function boletimUnidade(int $alunoId, int $turmaId, int $unidadeId): array
    {
        $rows = DB::table('avaliacoes as a')
            ->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
            ->join('tipos_avaliacao as ta', 'ta.id', '=', 'a.tipo_avaliacao_id')
            ->leftJoin('notas as n', fn ($j) => $j->on('n.avaliacao_id', '=', 'a.id')->where('n.aluno_id', $alunoId))
            ->where('a.turma_id', $turmaId)->where('a.unidade_letiva_id', $unidadeId)
            ->get(['d.id as disciplina_id', 'd.nome_disciplina', 'ta.peso', 'a.valor_maximo', 'n.id as nota_id', 'n.nota_valor']);

        $porDisc = [];
        foreach ($rows as $r) {
            $p = &$porDisc[(int) $r->disciplina_id];
            $p ??= ['nome' => $r->nome_disciplina, 'soma' => 0.0, 'pesos' => 0, 'avaliacoes' => 0, 'lancadas' => 0];
            $p['avaliacoes']++;
            if ($r->nota_id !== null && (float) $r->valor_maximo > 0) {
                $p['soma'] += ((float) $r->nota_valor / (float) $r->valor_maximo) * (int) $r->peso;
                $p['pesos'] += (int) $r->peso;
                $p['lancadas']++;
            }
            unset($p);
        }

        $saida = [];
        foreach ($porDisc as $id => $p) {
            $saida[$id] = [
                'disciplina_id'    => $id,
                'nome'             => $p['nome'],
                'media'            => $p['pesos'] > 0 ? round($p['soma'] / 10, 2) : null,       // igual ao boletim
                'pontos_avaliados' => round($p['pesos'] / 10, 1),                               // de 10
                'aproveitamento'   => $p['pesos'] > 0 ? round($p['soma'] / $p['pesos'] * 10, 1) : null,
                'avaliacoes'       => $p['avaliacoes'],
                'lancadas'         => $p['lancadas'],
            ];
        }
        return $saida;
    }

    private function proximas(int $turmaId, Carbon $hoje): array
    {
        return DB::table('avaliacoes as a')
            ->join('tipos_avaliacao as ta', 'ta.id', '=', 'a.tipo_avaliacao_id')
            ->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
            ->where('a.turma_id', $turmaId)
            ->whereBetween('a.data_aplicacao', [$hoje->toDateString(), $hoje->copy()->addDays(30)->toDateString()])
            ->orderBy('a.data_aplicacao')
            ->limit(6)
            ->get(['a.id', 'a.data_aplicacao', 'a.descricao', 'ta.nome_tipo', 'ta.cor', 'd.nome_disciplina'])
            ->map(fn ($a) => [
                'avaliacao_id' => (int) $a->id,
                'data'         => substr((string) $a->data_aplicacao, 0, 10),
                'dias'         => $this->periodos->dias($hoje, Carbon::parse($a->data_aplicacao)),
                'tipo'         => $a->nome_tipo,
                'cor'          => $a->cor,
                'disciplina'   => $a->nome_disciplina,
                'observacao'   => $a->descricao ?: null,
            ])->all();
    }

    /** Mensalidades da família: em atraso e próxima a vencer. */
    private function financeiro(array $alunoIds, Carbon $hoje): ?array
    {
        if (!$alunoIds || !DB::table('parcelas')->whereIn('aluno_id', $alunoIds)->exists()) {
            return null;
        }

        $abertas = DB::table('parcelas as p')
            ->join('alunos as a', 'a.id', '=', 'p.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->whereIn('p.aluno_id', $alunoIds)
            ->whereIn('p.status', ['pendente', 'vencido'])
            ->orderBy('p.data_vencimento')
            ->get(['p.id', 'p.numero_parcela', 'p.valor_final', 'p.data_vencimento', 'p.status', 'u.nome_completo as aluno']);

        $atrasadas = $abertas->filter(fn ($p) => $p->status === 'vencido' || $p->data_vencimento < $hoje->toDateString());
        $proxima = $abertas->first(fn ($p) => $p->data_vencimento >= $hoje->toDateString() && $p->status === 'pendente');

        return [
            'atrasadas' => [
                'quantidade' => $atrasadas->count(),
                'total'      => round((float) $atrasadas->sum('valor_final'), 2),
                'mais_antiga' => $atrasadas->first() ? substr((string) $atrasadas->first()->data_vencimento, 0, 10) : null,
            ],
            'proxima' => $proxima ? [
                'valor'      => (float) $proxima->valor_final,
                'vencimento' => substr((string) $proxima->data_vencimento, 0, 10),
                'dias'       => $this->periodos->dias($hoje, Carbon::parse($proxima->data_vencimento)),
                'aluno'      => $proxima->aluno,
                'parcela'    => (int) $proxima->numero_parcela,
            ] : null,
        ];
    }
}
