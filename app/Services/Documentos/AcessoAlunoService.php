<?php

namespace App\Services\Documentos;

use Illuminate\Support\Facades\DB;

/**
 * Equivalente a alunoAcessivelPeloUsuario() em api/helpers/escola_helper.php.
 * Administrador/Diretoria acessam qualquer aluno; Responsável só os próprios
 * dependentes (via aluno_responsavel). Nunca confia só no aluno_id do request.
 */
class AcessoAlunoService
{
    public function alunoAcessivel(object $jwtUser, int $alunoId): bool
    {
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        if (in_array('Administrador', $funcoes, true) || in_array('Diretoria', $funcoes, true)) {
            return true;
        }

        if (!in_array('Responsável', $funcoes, true) && !in_array('Responsavel', $funcoes, true)) {
            return false;
        }

        $usuarioId = (int) ($jwtUser->id ?? 0);

        return DB::table('responsaveis as r')
            ->join('aluno_responsavel as ar', 'r.id', '=', 'ar.responsavel_id')
            ->where('r.usuario_id', $usuarioId)
            ->where('ar.aluno_id', $alunoId)
            ->exists();
    }
}
