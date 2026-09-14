<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/admin/{post_aluno_responsavel,post_responsavel_aluno}.php —
 * mesma tabela (aluno_responsavel), duas direções de uso: vincular vários
 * ALUNOS a UM responsável (ResponsavelController::vincularAlunos()), ou vincular
 * vários RESPONSÁVEIS a UM aluno (AlunoController::vincularResponsaveis()).
 */
class AlunoResponsavelVinculoService
{
    /**
     * @param int $idFixo aluno_id (se $porResponsavel=false) ou responsavel_id (se true)
     * @param int[] $idsLote lado variável do vínculo
     * @return array{0:int, 1:string[]} [vínculos criados, avisos]
     */
    public function criar(int $idFixo, array $idsLote, bool $porResponsavel): array
    {
        $vinculosCriados = 0;
        $avisos = [];

        DB::transaction(function () use ($idFixo, $idsLote, $porResponsavel, &$vinculosCriados, &$avisos) {
            foreach ($idsLote as $idVariavel) {
                $idVariavel = (int) $idVariavel;
                $alunoId = $porResponsavel ? $idVariavel : $idFixo;
                $responsavelId = $porResponsavel ? $idFixo : $idVariavel;

                try {
                    // parentesco é NOT NULL sem default no schema local — o
                    // legado não informava esse campo (mesmo gotcha visto em
                    // AlunoController::store()).
                    DB::table('aluno_responsavel')->insert(['aluno_id' => $alunoId, 'responsavel_id' => $responsavelId, 'parentesco' => '']);
                    $vinculosCriados++;
                } catch (\Illuminate\Database\QueryException $e) {
                    $errno = (int) ($e->errorInfo[1] ?? 0);
                    if ($errno === 1062) {
                        $avisos[] = "Vínculo (Aluno ID $alunoId, Responsável ID $responsavelId) ignorado: já existe.";
                    } elseif ($errno === 1452) {
                        $avisos[] = "Vínculo (Aluno ID $alunoId, Responsável ID $responsavelId) ignorado: Aluno ou Responsável não existem.";
                    } else {
                        throw $e;
                    }
                }
            }
        });

        return [$vinculosCriados, $avisos];
    }
}
