<?php

namespace App\Services\Turmas;

use App\Services\Matriculas\NumeroMatriculaService;
use App\Services\Rematricula\RematriculaService;
use Illuminate\Support\Facades\DB;

/**
 * Encerramento do ano da turma. Regra do fluxo de renovação:
 *
 *   rematrícula (garante a vaga: contrato + número do ano novo)
 *     → encerramento do ano (resultado do ano + efetivação da vaga)
 *
 * Só segue na escola quem tem rematrícula para o ano seguinte — não existe
 * "promover" sem ela. Aqui se registra o resultado do ano de cada aluno e só
 * então ele muda de turma:
 * - promover (aprovado) / reter (retido): entra na turma do ano seguinte com
 *   o número reservado. Retido vai para a turma escolhida aqui (a reserva
 *   acompanha; o contrato continua o mesmo).
 * - concluir (formando) / nao_renovou: fecha a matrícula, tira da turma e
 *   inativa o aluno. Se havia rematrícula, ela e o contrato do ano novo são
 *   cancelados.
 */
class ViradaAnoService
{
    public function __construct(
        private NumeroMatriculaService $numeros,
        private RematriculaService $rematriculas,
    ) {
    }

    /**
     * Fim da matrícula de origem: hoje ou a última chamada lançada na turma
     * (o que for depois), para o histórico cobrir o ano letivo inteiro.
     */
    public function dataFim(object $origem): string
    {
        $ultimaChamada = DB::table('frequencia as f')
            ->join('turma_aluno as ta', 'ta.aluno_id', '=', 'f.aluno_id')
            ->where('ta.turma_id', $origem->id)
            ->whereYear('f.data', $origem->ano_letivo)
            ->max('f.data');
        return max(now()->toDateString(), (string) $ultimaChamada);
    }

    /** Último dia letivo cadastrado do ano (fim do último bimestre), ou null. */
    public function fimDoAnoLetivo(int $ano): ?string
    {
        return app(PeriodoLetivoService::class)->unidadesComDatas($ano)->max('data_fim');
    }

    /**
     * Tudo que a tela de encerramento precisa de uma turma: cada aluno com a
     * rematrícula (ou a falta dela) e o resultado sugerido pelas médias, a
     * campanha do ano seguinte e as turmas desse ano.
     */
    public function painel(int $turmaId): ?array
    {
        $turma = DB::table('turmas')->where('id', $turmaId)->first(['id', 'nome_turma', 'turno', 'ano_letivo', 'status']);
        if (!$turma) {
            return null;
        }
        $ano = (int) $turma->ano_letivo;
        $situacao = collect($this->rematriculas->situacaoParaVirada($turmaId))->keyBy('aluno_id');
        $notas = app(\App\Services\Documentos\DocumentosEscolaresService::class)->mediasDaTurma($turmaId)['alunos'] ?? [];

        $alunos = DB::table('turma_aluno as ta')->join('alunos as a', 'a.id', '=', 'ta.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('ta.turma_id', $turmaId)->orderBy('u.nome_completo')
            ->get(['a.id', 'u.nome_completo as nome', 'a.matricula', 'u.ativo'])
            ->map(function ($a) use ($situacao, $notas) {
                $n = $notas[$a->id] ?? null;
                return [
                    'aluno_id' => (int) $a->id, 'nome' => $a->nome, 'matricula' => $a->matricula, 'usuario_inativo' => !$a->ativo,
                    'rematricula' => $situacao[$a->id] ?? null,
                    'media_geral' => $n['media_geral'] ?? null,
                    'abaixo_da_media' => (int) ($n['abaixo'] ?? 0),
                    // Sugestão pelas médias anuais (a escola decide): alguma disciplina abaixo da mínima → retido.
                    'resultado_sugerido' => !$n || $n['media_geral'] === null ? null : (($n['abaixo'] ?? 0) > 0 ? 'retido' : 'aprovado'),
                ];
            })->values()->all();

        $campanha = $this->rematriculas->disponivel()
            ? DB::table('rematricula_campanhas')->where('ano_destino', $ano + 1)->first(['id', 'status']) : null;

        return [
            'turma' => ['id' => (int) $turma->id, 'nome' => $turma->nome_turma, 'turno' => $turma->turno, 'ano_letivo' => $ano, 'status' => $turma->status],
            'ano_destino' => $ano + 1,
            'campanha' => $campanha ? ['id' => (int) $campanha->id, 'status' => $campanha->status] : null,
            'fim_ano_letivo' => $this->fimDoAnoLetivo($ano),
            'turmas_destino' => $this->turmasDoAno($ano + 1),
            'turma_retidos' => $this->rematriculas->turmaMesmaSerie($turmaId),
            'alunos' => $alunos,
        ];
    }

    /** Turmas ativas do ano com os planos vinculados (para avisar quando o retido muda para turma de outro plano). */
    private function turmasDoAno(int $ano): array
    {
        $turmas = DB::table('turmas')->where('ano_letivo', $ano)->where('status', 'Ativa')->orderBy('nome_turma')->get(['id', 'nome_turma', 'turno']);
        $planos = app(\App\Services\Financeiro\ContratoFinanceiroService::class)->planosPorTurma($turmas->pluck('id')->map(fn ($v) => (int) $v)->all());
        return $turmas->map(fn ($t) => [
            'id' => (int) $t->id, 'nome' => $t->nome_turma, 'turno' => $t->turno,
            'planos' => array_column($planos[(int) $t->id] ?? [], 'id'),
        ])->all();
    }

    /** @throws \Exception com a mensagem para a tela */
    public function processarAluno(object $origem, int $alunoId, string $acao, int $destinoId, string $dataFim, int $usuarioId): void
    {
        DB::transaction(function () use ($origem, $alunoId, $acao, $destinoId, $dataFim, $usuarioId) {
            $vinculado = DB::table('turma_aluno')->where('aluno_id', $alunoId)->where('turma_id', $origem->id)->exists();
            if (!$vinculado) {
                throw new \Exception('Aluno não está nesta turma.');
            }
            $reserva = $this->rematriculas->reservaDoAluno($alunoId, (int) $origem->ano_letivo);

            if (in_array($acao, ['promover', 'reter'], true)) {
                $destino = DB::table('turmas')->where('id', $destinoId)->first(['id', 'ano_letivo', 'status']);
                if (!$destino || $destino->status !== 'Ativa') {
                    throw new \Exception('Turma de destino não existe ou não está ativa.');
                }
                $anoSeguinte = (int) $origem->ano_letivo + 1;
                if ((int) $destino->ano_letivo !== $anoSeguinte) {
                    throw new \Exception("A turma de destino precisa ser de $anoSeguinte. Para trocar de turma no mesmo ano, use Editar aluno.");
                }
                if (!$reserva) {
                    throw new \Exception("Sem rematrícula para $anoSeguinte. Faça a rematrícula antes (botão Rematricular).");
                }

                $this->fecharMatricula($alunoId, $origem, 'concluida', 'conclusao',
                    $acao === 'reter' ? 'Retido — permanece na mesma etapa' : null,
                    $acao === 'reter' ? 'retido' : 'promovido', $dataFim, $usuarioId);

                // Ano novo = número novo, o reservado na rematrícula (rematrícula antiga sem número: o próximo).
                $numero = $reserva->numero_matricula ?: $this->numeros->proximo($anoSeguinte);
                DB::table('alunos')->where('id', $alunoId)->update(['matricula' => $numero]);

                DB::table('turma_aluno')->where('aluno_id', $alunoId)->where('turma_id', $origem->id)
                    ->update(['turma_id' => $destino->id]);
                $matriculaId = DB::table('matriculas')->insertGetId($this->numeros->linha([
                    'aluno_id' => $alunoId, 'turma_id' => $destino->id, 'ano_letivo' => $anoSeguinte,
                    'data_inicio' => $anoSeguinte . '-01-01', 'status' => 'ativa', 'origem' => 'sistema',
                ], $numero));

                $this->rematriculas->efetivar((int) $reserva->id, (int) $destino->id, $matriculaId);
                return;
            }

            // concluir / nao_renovou: sai da escola ao fim do ano.
            $concluiu = $acao === 'concluir';
            $this->fecharMatricula($alunoId, $origem, 'concluida', $concluiu ? 'conclusao' : 'outro',
                $concluiu ? 'Concluiu a etapa (formando)' : 'Não renovou a matrícula',
                $concluiu ? 'concluiu' : 'nao_renovou', $dataFim, $usuarioId);
            DB::table('turma_aluno')->where('aluno_id', $alunoId)->where('turma_id', $origem->id)->delete();
            DB::table('alunos')->where('id', $alunoId)->update(['status_matricula' => $concluiu ? 'Concluido' : 'Inativo']);
            $usuarioAluno = DB::table('alunos')->where('id', $alunoId)->value('usuario_id');
            DB::table('usuarios')->where('id', $usuarioAluno)->update(['ativo' => 0]);

            if ($reserva) {
                $this->rematriculas->cancelar((int) $reserva->id, $usuarioId);
            }
        });
    }

    /**
     * Rematrícula de quem saiu no encerramento do ano (não renovou / formando)
     * e voltou: reativa o aluno e já o coloca na turma do ano novo com o
     * número reservado. O resultado do ano anterior fica como estava.
     */
    public function efetivarTardia(int $rematriculaId, int $usuarioId): void
    {
        DB::transaction(function () use ($rematriculaId) {
            $r = DB::table('rematriculas as r')->join('rematricula_campanhas as c', 'c.id', '=', 'r.campanha_id')
                ->where('r.id', $rematriculaId)->whereNull('r.efetivada_em')->lockForUpdate()
                ->first(['r.id', 'r.aluno_id', 'r.turma_destino_id', 'r.numero_matricula', 'c.ano_destino']);
            if (!$r) {
                return;
            }
            $alunoId = (int) $r->aluno_id;
            $usuarioAluno = DB::table('alunos')->where('id', $alunoId)->value('usuario_id');
            DB::table('usuarios')->where('id', $usuarioAluno)->update(['ativo' => 1]);
            DB::table('alunos')->where('id', $alunoId)->update(['status_matricula' => 'Ativo', 'matricula' => $r->numero_matricula]);
            DB::table('turma_aluno')->where('aluno_id', $alunoId)->delete();
            DB::table('turma_aluno')->insert(['aluno_id' => $alunoId, 'turma_id' => $r->turma_destino_id]);
            $matriculaId = DB::table('matriculas')->insertGetId($this->numeros->linha([
                'aluno_id' => $alunoId, 'turma_id' => $r->turma_destino_id, 'ano_letivo' => $r->ano_destino,
                'data_inicio' => max(now()->toDateString(), $r->ano_destino . '-01-01'), 'status' => 'ativa', 'origem' => 'sistema',
            ], $r->numero_matricula));
            $this->rematriculas->efetivar((int) $r->id, (int) $r->turma_destino_id, $matriculaId);
        });
    }

    /** Alunos (ativos) que ainda estão na turma — sem nenhum, ela pode ser encerrada. */
    public function contarRestantes(int $turmaId): int
    {
        return DB::table('turma_aluno as ta')->join('alunos as al', 'al.id', '=', 'ta.aluno_id')
            ->join('usuarios as ua', 'ua.id', '=', 'al.usuario_id')->where('ua.ativo', 1)
            ->where('ta.turma_id', $turmaId)->count();
    }

    private function fecharMatricula(int $alunoId, object $turma, string $status, string $motivo, ?string $detalhe, ?string $resultado, string $dataFim, int $usuarioId): void
    {
        $dados = [
            'status' => $status, 'data_fim' => $dataFim, 'motivo_encerramento' => $motivo,
            'motivo_detalhe' => $detalhe, 'encerrado_por' => $usuarioId, 'encerrado_em' => now(),
        ];
        if ($this->numeros->porAno()) {
            $dados['resultado'] = $resultado;
        }
        $fechadas = DB::table('matriculas')
            ->where('aluno_id', $alunoId)->where('turma_id', $turma->id)->where('status', 'ativa')->update($dados);

        // Vínculo sem matrícula registrada (dados antigos): cria o histórico já fechado.
        if ($fechadas === 0) {
            DB::table('matriculas')->insert($this->numeros->linha([
                'aluno_id' => $alunoId, 'turma_id' => $turma->id, 'ano_letivo' => $turma->ano_letivo, 'origem' => 'sistema',
            ] + $dados, DB::table('alunos')->where('id', $alunoId)->value('matricula')));
        }
    }
}
