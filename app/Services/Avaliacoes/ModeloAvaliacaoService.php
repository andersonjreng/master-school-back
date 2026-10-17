<?php

namespace App\Services\Avaliacoes;

use Illuminate\Support\Facades\DB;

/**
 * Regras dos modelos de avaliação (grupos de tipos cujos pesos somam 100 =
 * 10 pontos na média bimestral). Ver migration criar_modelos_avaliacao.
 *
 * - Um modelo vale para a turma se estiver ativo e for de todas as turmas ou
 *   vinculado a ela.
 * - Numa disciplina/bimestre, a primeira avaliação define o modelo; as
 *   demais só podem usar tipos desse modelo, sem repetir tipo.
 */
class ModeloAvaliacaoService
{
    public const PESO_TOTAL = 100;

    /** @return int[] ids dos modelos ativos que valem para a turma */
    public function modelosDaTurma(int $turmaId): array
    {
        return DB::table('modelos_avaliacao as m')
            ->where('m.ativo', 1)
            ->where(function ($q) use ($turmaId) {
                $q->where('m.todas_turmas', 1)
                  ->orWhereExists(fn ($s) => $s->from('modelo_avaliacao_turma as mt')
                      ->whereColumn('mt.modelo_avaliacao_id', 'm.id')
                      ->where('mt.turma_id', $turmaId));
            })
            ->pluck('m.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Situação de uma disciplina/bimestre da turma: modelo em uso, pontos já
     * distribuídos, tipos já usados e tipos que faltam para fechar 10.
     *
     * @return array{modelo_id: ?int, modelo_nome: ?string, peso_lancado: int, tipos_usados: int[], tipos_faltantes: array}
     */
    public function situacao(int $turmaId, int $disciplinaId, int $unidadeLetivaId, ?int $exceto = null): array
    {
        $usadas = DB::table('avaliacoes as a')
            ->join('tipos_avaliacao as t', 't.id', '=', 'a.tipo_avaliacao_id')
            ->where('a.turma_id', $turmaId)
            ->where('a.disciplina_id', $disciplinaId)
            ->where('a.unidade_letiva_id', $unidadeLetivaId)
            ->when($exceto, fn ($q) => $q->where('a.id', '<>', $exceto))
            ->get(['t.id', 't.peso', 't.modelo_avaliacao_id']);

        $modeloId = $usadas->pluck('modelo_avaliacao_id')->filter()->first();
        $tiposUsados = $usadas->pluck('id')->map(fn ($id) => (int) $id)->all();

        $faltantes = [];
        $modeloNome = null;
        if ($modeloId) {
            $modeloNome = DB::table('modelos_avaliacao')->where('id', $modeloId)->value('nome');
            $faltantes = DB::table('tipos_avaliacao')
                ->where('modelo_avaliacao_id', $modeloId)
                ->whereNotIn('id', $tiposUsados ?: [0])
                ->orderBy('ordem')
                ->get(['id', 'nome_tipo', 'peso'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'nome_tipo' => $t->nome_tipo, 'peso' => (int) $t->peso])
                ->all();
        }

        return [
            'modelo_id'       => $modeloId ? (int) $modeloId : null,
            'modelo_nome'     => $modeloNome,
            'peso_lancado'    => (int) $usadas->sum('peso'),
            'tipos_usados'    => $tiposUsados,
            'tipos_faltantes' => $faltantes,
        ];
    }

    /**
     * Motivo pelo qual o tipo não pode ser usado na disciplina/bimestre, ou
     * null se pode. $exceto = avaliação sendo editada (não conta como usada).
     */
    public function motivoBloqueio(int $tipoId, int $turmaId, int $disciplinaId, int $unidadeLetivaId, ?int $exceto = null): ?string
    {
        $tipo = DB::table('tipos_avaliacao')->where('id', $tipoId)->first(['nome_tipo', 'modelo_avaliacao_id']);
        if (!$tipo) {
            return 'Tipo de avaliação inexistente.';
        }
        if (!$tipo->modelo_avaliacao_id || !in_array((int) $tipo->modelo_avaliacao_id, $this->modelosDaTurma($turmaId), true)) {
            return "O tipo \"{$tipo->nome_tipo}\" não está disponível para esta turma (modelo inativo ou não vinculado — veja Configurações → Parametrizações).";
        }

        $situacao = $this->situacao($turmaId, $disciplinaId, $unidadeLetivaId, $exceto);
        if ($situacao['modelo_id'] && $situacao['modelo_id'] !== (int) $tipo->modelo_avaliacao_id) {
            return "Esta disciplina já usa o modelo \"{$situacao['modelo_nome']}\" neste bimestre. Use um dos tipos desse modelo para a nota fechar em 10.";
        }
        if (in_array($tipoId, $situacao['tipos_usados'], true)) {
            return "Já existe uma avaliação do tipo \"{$tipo->nome_tipo}\" nesta disciplina neste bimestre.";
        }

        return null;
    }
}
