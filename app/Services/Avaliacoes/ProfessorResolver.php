<?php

namespace App\Services\Avaliacoes;

use Illuminate\Support\Facades\DB;

/**
 * Resolve professores.id a partir do usuario_id do JWT — repetido em quase
 * todo endpoint de api/avaliacoes/*.php pra checar ownership.
 */
class ProfessorResolver
{
    public function idDoUsuario(?int $usuarioId): ?int
    {
        if (!$usuarioId) {
            return null;
        }

        $id = DB::table('professores')->where('usuario_id', $usuarioId)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
