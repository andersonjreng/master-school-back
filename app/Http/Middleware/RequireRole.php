<?php

namespace App\Http\Middleware;

use App\Support\Papeis;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Equivalente a require_role() em api/helpers/auth_helper.php.
 * Use depois do VerifyLegacyJwt: max.role:Responsável — ou max.role:equipe
 * (qualquer perfil da equipe da escola). Para acesso de gestão prefira
 * max.pode:<permissão> (RequirePermission), que respeita as exceções.
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$papeisPermitidos): Response
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        $permitidos = [];
        foreach ($papeisPermitidos as $papel) {
            array_push($permitidos, ...match ($papel) {
                'equipe' => Papeis::EQUIPE,
                Papeis::RESPONSAVEL => [Papeis::RESPONSAVEL, 'Responsavel'],
                default => [$papel],
            });
        }

        if (empty(array_intersect($permitidos, $funcoes))) {
            return response()->json([
                'error' => 'Acesso negado. Requer um dos papéis: ' . implode(', ', $papeisPermitidos),
            ], 403);
        }

        return $next($request);
    }
}
