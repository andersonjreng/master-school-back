<?php

namespace App\Http\Middleware;

use App\Services\Sistema\PermissaoService;
use App\Support\Permissoes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Use depois do VerifyLegacyJwt: max.pode:financeiro.operar (ou várias,
 * separadas por vírgula — basta ter uma). Ver App\Support\Permissoes.
 */
class RequirePermission
{
    public function __construct(private PermissaoService $permissoes)
    {
    }

    public function handle(Request $request, Closure $next, string ...$permissoes): Response
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        if (!$this->permissoes->pode($funcoes, ...$permissoes)) {
            $rotulos = array_map(fn ($p) => Permissoes::CATALOGO[$p][1] ?? $p, $permissoes);

            return response()->json([
                'error' => 'Acesso negado. Seu perfil não tem permissão para: ' . implode(' / ', $rotulos) . '.',
            ], 403);
        }

        return $next($request);
    }
}
