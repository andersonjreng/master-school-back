<?php

namespace App\Services\Frequencia;

use App\Support\Papeis;
use App\Support\Permissoes;
use Illuminate\Support\Facades\DB;

/**
 * Controle de acesso compartilhado pelos endpoints de frequência (equivalente
 * à checagem repetida em api/frequencia/*.php): professor e quem acompanha
 * todas as turmas (permissão pedagogico.ver) têm acesso amplo; Responsável só
 * pode consultar os próprios dependentes.
 */
class FrequenciaAccessService
{
    /** Visões da escola toda (resumo geral/mensal). */
    public function acompanhaTodasTurmas(object $jwtUser): bool
    {
        return Permissoes::pode($this->funcoes($jwtUser), Permissoes::PEDAGOGICO_VER);
    }

    public function acessoAmplo(object $jwtUser): bool
    {
        return Papeis::ehProfessor($this->funcoes($jwtUser)) || $this->acompanhaTodasTurmas($jwtUser);
    }

    /** Pode consultar alguma frequência (amplo ou dos próprios dependentes). */
    public function podeConsultar(object $jwtUser): bool
    {
        return $this->acessoAmplo($jwtUser) || Papeis::ehResponsavel($this->funcoes($jwtUser));
    }

    /**
     * True quando o usuário é Responsável e NÃO tem acesso amplo (ou seja,
     * precisa passar pela checagem de dependente).
     */
    public function responsavelSemAcessoAmplo(object $jwtUser): bool
    {
        return Papeis::ehResponsavel($this->funcoes($jwtUser)) && !$this->acessoAmplo($jwtUser);
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
