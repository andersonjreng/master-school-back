<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Perfis de quem fez a requisição (do JWT validado em VerifyLegacyJwt). */
    protected function funcoes(Request $request): array
    {
        $jwtUser = $request->attributes->get('max_user');

        return (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
    }
}
