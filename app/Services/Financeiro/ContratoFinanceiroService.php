<?php

namespace App\Services\Financeiro;

use Illuminate\Support\Facades\DB;

/**
 * "Contrato" = matrícula financeira do aluno num ano letivo.
 *
 * Junta o que o cadastro do aluno, a virada de ano e a tela de Contratos
 * precisam saber: quem está numa turma do ano mas ainda sem contrato, qual
 * plano sugerir (planos vinculados à turma) e quem foi dispensado (ex.:
 * bolsa integral, filho de funcionário) para não aparecer como pendência.
 */
class ContratoFinanceiroService
{
    public function __construct(private FinanceiroConfigService $config)
    {
    }

    /** Alunos ativos em turmas ativas do ano, sem contrato ativo e sem dispensa. */
    public function semContrato(int $ano): array
    {
        $rows = DB::table('turma_aluno as ta')
            ->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->join('alunos as a', 'a.id', '=', 'ta.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('t.ano_letivo', $ano)
            ->where('t.status', 'Ativa')
            ->where('u.ativo', 1)
            ->whereNotExists(fn ($q) => $q->from('matriculas_financeiras as mf')
                ->whereColumn('mf.aluno_id', 'a.id')->where('mf.ano_letivo', (string) $ano)->where('mf.status', 'ativa'))
            ->whereNotExists(fn ($q) => $q->from('financeiro_dispensas as d')
                ->whereColumn('d.aluno_id', 'a.id')->where('d.ano_letivo', $ano))
            ->orderBy('t.nome_turma')
            ->orderBy('u.nome_completo')
            ->get(['a.id as aluno_id', 'u.nome_completo as aluno', 't.id as turma_id', 't.nome_turma as turma']);

        $sugeridos = $this->planosPorTurma($rows->pluck('turma_id')->unique()->all());

        $vistos = [];
        $data = [];
        foreach ($rows as $r) {
            if (isset($vistos[$r->aluno_id])) {
                continue;
            }
            $vistos[$r->aluno_id] = true;
            $planos = $sugeridos[$r->turma_id] ?? [];
            $data[] = [
                'aluno_id'       => (int) $r->aluno_id,
                'aluno'          => $r->aluno,
                'turma_id'       => (int) $r->turma_id,
                'turma'          => $r->turma,
                'plano_sugerido' => count($planos) === 1 ? $planos[0] : null,
                'planos_turma'   => $planos,
            ];
        }

        return $data;
    }

    public function contarSemContrato(int $ano): int
    {
        return count($this->semContrato($ano));
    }

    /** Anos com turmas ativas (normalmente o atual e, perto da virada, o próximo). */
    public function anosComTurmasAtivas(): array
    {
        return DB::table('turmas')->where('status', 'Ativa')->distinct()->orderBy('ano_letivo')
            ->pluck('ano_letivo')->map(fn ($a) => (int) $a)->all();
    }

    /**
     * Tudo que a tela precisa para oferecer o contrato de um aluno numa turma:
     * se já tem, se foi dispensado, planos sugeridos e a data de início.
     */
    public function sugestao(int $alunoId, ?int $turmaId, int $ano): array
    {
        $contrato = DB::table('matriculas_financeiras as mf')
            ->join('planos_pagamento as p', 'p.id', '=', 'mf.plano_id')
            ->where('mf.aluno_id', $alunoId)->where('mf.ano_letivo', (string) $ano)->where('mf.status', 'ativa')
            ->first(['mf.id', 'p.nome as plano']);
        $dispensa = DB::table('financeiro_dispensas')->where('aluno_id', $alunoId)->where('ano_letivo', $ano)
            ->first(['motivo', 'criado_em']);
        $planos = $turmaId ? ($this->planosPorTurma([$turmaId])[$turmaId] ?? []) : [];

        return [
            'ano_letivo'      => $ano,
            'pergunta_ativa'  => $this->config->todas()['financeiro_na_matricula'] === 'perguntar',
            'contrato'        => $contrato ? ['id' => (int) $contrato->id, 'plano' => $contrato->plano] : null,
            'dispensa'        => $dispensa,
            'planos_turma'    => $planos,
            'plano_sugerido'  => count($planos) === 1 ? $planos[0] : null,
            'data_inicio'     => $this->dataInicioSugerida($ano),
        ];
    }

    /** Ano corrente: 1º dia do mês atual (entrou no meio do ano). Outro ano: 1º de janeiro. */
    public function dataInicioSugerida(int $ano): string
    {
        return $ano === (int) now()->year ? now()->startOfMonth()->toDateString() : "$ano-01-01";
    }

    /** @return array<int, array<int, array{id:int, nome:string, valor_total:float, numero_parcelas:int, valor_parcela:float}>> */
    public function planosPorTurma(array $turmaIds): array
    {
        if (!$turmaIds) {
            return [];
        }
        $rows = DB::table('plano_pagamento_turma as pt')
            ->join('planos_pagamento as p', 'p.id', '=', 'pt.plano_id')
            ->whereIn('pt.turma_id', $turmaIds)
            ->where('p.ativo', 1)
            ->orderBy('p.nome')
            ->get(['pt.turma_id', 'p.id', 'p.nome', 'p.valor_total', 'p.numero_parcelas']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->turma_id][] = [
                'id'              => (int) $r->id,
                'nome'            => $r->nome,
                'valor_total'     => (float) $r->valor_total,
                'numero_parcelas' => (int) $r->numero_parcelas,
                'valor_parcela'   => round((float) $r->valor_total / max(1, (int) $r->numero_parcelas), 2),
            ];
        }
        return $out;
    }

    public function dispensas(int $ano): array
    {
        return DB::table('financeiro_dispensas as d')
            ->join('alunos as a', 'a.id', '=', 'd.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->leftJoin('usuarios as c', 'c.id', '=', 'd.criado_por')
            ->where('d.ano_letivo', $ano)
            ->orderBy('u.nome_completo')
            ->get(['d.id', 'd.aluno_id', 'u.nome_completo as aluno', 'd.motivo', 'd.criado_em', 'c.nome_completo as criado_por'])
            ->all();
    }

    public function dispensar(int $alunoId, int $ano, ?string $motivo, int $usuarioId): void
    {
        DB::table('financeiro_dispensas')->updateOrInsert(
            ['aluno_id' => $alunoId, 'ano_letivo' => $ano],
            ['motivo' => $motivo ? mb_substr($motivo, 0, 255) : null, 'criado_por' => $usuarioId]
        );
    }

    public function removerDispensa(int $alunoId, int $ano): void
    {
        DB::table('financeiro_dispensas')->where('aluno_id', $alunoId)->where('ano_letivo', $ano)->delete();
    }
}
