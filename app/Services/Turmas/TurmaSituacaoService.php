<?php

namespace App\Services\Turmas;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Situação da turma no ciclo do ano letivo.
 *
 * - Congelamento: só turma 'Ativa' aceita lançamentos/alterações (frequência,
 *   avaliações, notas, registros de aula, BNCC). Encerrada/Inativa é somente
 *   leitura para todos — para corrigir algo, o admin reabre a turma.
 *
 * - Alunos da turma ("roster"): turma ativa → vínculo atual (turma_aluno),
 *   como sempre foi. Turma encerrada/inativa → todos que passaram por ela no
 *   histórico de matrículas (inclusive transferidos no meio do ano) + vínculo
 *   atual, para que a turma do ano passado continue mostrando seus alunos e
 *   registros depois da promoção para o ano seguinte.
 */
class TurmaSituacaoService
{
    // Sem cache em propriedade: com Octane o service pode viver entre
    // requisições e guardaria o status antigo de uma turma recém-encerrada.
    public function turma(int $turmaId): ?object
    {
        return DB::table('turmas')
            ->where('id', $turmaId)
            ->first(['id', 'nome_turma', 'ano_letivo', 'status', 'encerrada_em']);
    }

    public function editavel(int $turmaId): bool
    {
        return ($this->turma($turmaId)?->status ?? null) === 'Ativa';
    }

    /** Resposta 423 pronta quando a turma está congelada; null se pode alterar. */
    public function bloqueio(int $turmaId): ?JsonResponse
    {
        $turma = $this->turma($turmaId);
        if (!$turma) {
            return response()->json(['error' => 'Turma não encontrada.'], 404);
        }
        if ($turma->status === 'Ativa') {
            return null;
        }

        $situacao = $turma->status === 'Encerrada' ? 'encerrada' : 'inativa';
        return response()->json([
            'error'   => 'Turma ' . $situacao . '.',
            'message' => "A turma {$turma->nome_turma} ({$turma->ano_letivo}) está {$situacao} e não aceita alterações. "
                . 'Para corrigir algo, um administrador precisa reabri-la em Cadastros → Turmas.',
            'turma_congelada' => true,
        ], 423);
    }

    /** Turma da avaliação, para checar bloqueio a partir de avaliacao_id. */
    public function turmaDaAvaliacao(int $avaliacaoId): ?int
    {
        $id = DB::table('avaliacoes')->where('id', $avaliacaoId)->value('turma_id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * Subquery com os aluno_id da turma (ver regra no topo da classe).
     *
     * @return array{0: string, 1: array} [sql, bindings] — use como
     *         "JOIN ({$sql}) roster ON roster.aluno_id = a.id"
     */
    public function rosterSql(int $turmaId): array
    {
        if ($this->editavel($turmaId)) {
            return ['SELECT aluno_id FROM turma_aluno WHERE turma_id = ?', [$turmaId]];
        }

        return [
            "SELECT aluno_id FROM matriculas WHERE turma_id = ?
             UNION
             SELECT aluno_id FROM turma_aluno WHERE turma_id = ?",
            [$turmaId, $turmaId],
        ];
    }

    /**
     * Substituto de "turma_aluno" em queries existentes: mesmas colunas
     * (aluno_id, turma_id). Use como "FROM {$tabela} ta ... WHERE ta.turma_id = ?".
     */
    public function tabelaRoster(int $turmaId): string
    {
        if ($this->editavel($turmaId)) {
            return 'turma_aluno';
        }

        return "(SELECT aluno_id, turma_id FROM matriculas
                 UNION
                 SELECT aluno_id, turma_id FROM turma_aluno)";
    }

    /** Turma histórica (não ativa) mostra também alunos hoje inativos (ex.: formandos). */
    public function incluiInativos(int $turmaId): bool
    {
        return !$this->editavel($turmaId);
    }
}
