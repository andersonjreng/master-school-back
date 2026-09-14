<?php

namespace App\Services\Frequencia;

use Illuminate\Support\Facades\DB;

/**
 * Controle de acesso compartilhado pelos endpoints de frequência (equivalente
 * à checagem repetida em api/frequencia/*.php): Administrador/Professor têm
 * acesso amplo; Responsável só pode consultar os próprios dependentes.
 */
class FrequenciaAccessService
{
    public function possuiPapel(object $jwtUser, array $papeis): bool
    {
        return !empty(array_intersect($papeis, $this->funcoes($jwtUser)));
    }

    /**
     * True quando o usuário é Responsável e NÃO tem nenhum dos papéis de acesso
     * amplo informados (ou seja, precisa passar pela checagem de dependente).
     */
    public function responsavelSemAcessoAmplo(object $jwtUser, array $papeisAmplos = ['Administrador', 'Professor']): bool
    {
        $funcoes = $this->funcoes($jwtUser);
        $ehResponsavel = in_array('Responsável', $funcoes, true) || in_array('Responsavel', $funcoes, true);

        return $ehResponsavel && empty(array_intersect($papeisAmplos, $funcoes));
    }

    public function dependenteValido(object $jwtUser, int $alunoId): bool
    {
        $usuarioId = (int) ($jwtUser->id ?? 0);

        return DB::table('responsaveis as r')
            ->join('aluno_responsavel as ar', 'r.id', '=', 'ar.responsavel_id')
            ->where('r.usuario_id', $usuarioId)
            ->where('ar.aluno_id', $alunoId)
            ->exists();
    }

    private function funcoes(object $jwtUser): array
    {
        return (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
    }
}
