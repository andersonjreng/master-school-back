<?php

namespace App\Support;

/**
 * Papéis de acesso. "Gestão" = Administrador + Diretoria: a direção tem
 * acesso a tudo que o administrador tem, exceto o que é técnico/auditoria
 * (logs do sistema) e conceder o papel Administrador a alguém.
 */
final class Papeis
{
    public const GESTAO = ['Administrador', 'Diretoria'];

    public static function ehGestor(array $funcoes): bool
    {
        return (bool) array_intersect(self::GESTAO, $funcoes);
    }

    public static function ehAdministrador(array $funcoes): bool
    {
        return in_array('Administrador', $funcoes, true);
    }
}
