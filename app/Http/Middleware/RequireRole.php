<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Equivalente a require_role() em api/helpers/auth_helper.php.
 * Use depois do VerifyLegacyJwt: max.role:Administrador,Diretoria
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$papeisPermitidos): Response
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        if (empty(array_intersect($papeisPermitidos, $funcoes))) {
            return response()->json([
                'error' => 'Acesso negado. Requer um dos papéis: ' . implode(', ', $papeisPermitidos),
            ], 403);
        }

        return $next($request);
    }
}
