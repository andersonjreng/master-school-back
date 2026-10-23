<?php

namespace App\Services\Documentos;

use Illuminate\Support\Facades\DB;

/**
 * Equivalente a alunoAcessivelPeloUsuario() em api/helpers/escola_helper.php.
 * Quem emite documentos (permissão documentos.emitir) acessa qualquer aluno; Responsável só os próprios
 * dependentes (via aluno_responsavel). Nunca confia só no aluno_id do request.
 */
class AcessoAlunoService
{
    public function alunoAcessivel(object $jwtUser, int $alunoId): bool
    {
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        if (\App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::DOCUMENTOS_EMITIR)) {
            return true;
        }

        if (!\App\Support\Papeis::ehResponsavel($funcoes)) {
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
