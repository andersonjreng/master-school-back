<?php

namespace App\Services\Turmas;

use App\Services\Matriculas\NumeroMatriculaService;
use App\Services\Rematricula\RematriculaService;
use Illuminate\Support\Facades\DB;

/**
 * Encerramento do ano da turma (virada): decide o destino de cada aluno e
 * só então ele muda de turma. Quem fez a rematrícula já tem a vaga, o
 * contrato e o número de matrícula do ano novo reservados — aqui a reserva
 * é efetivada (matrícula ativa na turma nova com o número reservado).
 *
 * Ações por aluno:
 * - promover / reter: vai para a turma de destino (ativa). A matrícula de
 *   origem fecha como 'concluida' (ano concluído) ou 'transferida' (mesmo ano).
 *   Retido com rematrícula: a reserva passa para a turma escolhida aqui.
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
                if ((int) $destino->id === (int) $origem->id) {
                    throw new \Exception('A turma de destino deve ser diferente da origem.');
                }

                $anoConcluido = (int) $destino->ano_letivo > (int) $origem->ano_letivo;
                $this->fecharMatricula($alunoId, $origem, $anoConcluido ? 'concluida' : 'transferida',
                    $anoConcluido ? 'conclusao' : 'outro', $acao === 'reter' ? 'Retido — permanece na mesma etapa' : null,
                    $anoConcluido ? ($acao === 'reter' ? 'retido' : 'promovido') : null, $dataFim, $usuarioId);

                // Ano novo = número novo: o reservado na rematrícula ou o próximo da sequência.
                $numero = DB::table('alunos')->where('id', $alunoId)->value('matricula');
                if ($anoConcluido) {
                    $numero = $reserva && (int) $reserva->ano_destino === (int) $destino->ano_letivo && $reserva->numero_matricula
                        ? $reserva->numero_matricula
                        : $this->numeros->proximo((int) $destino->ano_letivo);
                    DB::table('alunos')->where('id', $alunoId)->update(['matricula' => $numero]);
                }

                DB::table('turma_aluno')->where('aluno_id', $alunoId)->where('turma_id', $origem->id)
                    ->update(['turma_id' => $destino->id]);
                $matriculaId = DB::table('matriculas')->insertGetId($this->numeros->linha([
                    'aluno_id' => $alunoId, 'turma_id' => $destino->id, 'ano_letivo' => $destino->ano_letivo,
                    'data_inicio' => $anoConcluido ? $destino->ano_letivo . '-01-01' : now()->toDateString(),
                    'status' => 'ativa', 'origem' => 'sistema',
                ], $numero));

                if ($reserva && $anoConcluido) {
                    $this->rematriculas->efetivar((int) $reserva->id, (int) $destino->id, $matriculaId);
                }
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
