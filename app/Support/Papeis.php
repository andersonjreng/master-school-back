<?php

namespace App\Support;

/**
 * Papéis (perfis) de acesso — os nomes gravados em `funcoes.nome_funcao`.
 *
 * O que cada perfil pode fazer NÃO se decide mais aqui: é por permissão
 * (App\Support\Permissoes), com um padrão por perfil e exceções que a escola
 * configura na tela Configurações → Permissões. Aqui ficam só as perguntas
 * de "quem é a pessoa": equipe da escola, família, administrador.
 */
final class Papeis
{
    public const ADMINISTRADOR = 'Administrador';
    public const DIRETORIA = 'Diretoria';
    public const COORDENACAO = 'Coordenação Pedagógica';
    public const SECRETARIA = 'Secretaria';
    public const TESOURARIA = 'Tesouraria';
    public const PROFESSOR = 'Professor';
    public const RESPONSAVEL = 'Responsável';
    public const ALUNO = 'Aluno';

    /** Equipe administrativa (trabalha "na escola", não numa turma). */
    public const ADMINISTRATIVOS = [self::ADMINISTRADOR, self::DIRETORIA, self::COORDENACAO, self::SECRETARIA, self::TESOURARIA];

    /** Toda a equipe da escola: administrativos + professores. */
    public const EQUIPE = [...self::ADMINISTRATIVOS, self::PROFESSOR];

    public static function ehAdministrador(array $funcoes): bool
    {
        return in_array(self::ADMINISTRADOR, $funcoes, true);
    }

    public static function ehAdministrativo(array $funcoes): bool
    {
        return (bool) array_intersect(self::ADMINISTRATIVOS, $funcoes);
    }

    public static function ehEquipe(array $funcoes): bool
    {
        return (bool) array_intersect(self::EQUIPE, $funcoes);
    }

    public static function ehProfessor(array $funcoes): bool
    {
        return in_array(self::PROFESSOR, $funcoes, true) || in_array('Docente', $funcoes, true);
    }

    /** O nome antigo sem acento ainda aparece em alguns dados/tokens. */
    public static function ehResponsavel(array $funcoes): bool
    {
        return in_array(self::RESPONSAVEL, $funcoes, true) || in_array('Responsavel', $funcoes, true);
    }

    /** Família sem nenhum papel da equipe (vê só os próprios dependentes). */
    public static function somenteFamilia(array $funcoes): bool
    {
        return !self::ehEquipe($funcoes);
    }
}
