<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\AuthController;
use Closure;
use Illuminate\Support\Facades\DB;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida o mesmo JWT (HS256) emitido pelo login legado em api/auth/.
 * Substitui o comportamento do agent.php original, que não validava o token
 * e confiava cegamente no objeto "usuario" enviado pelo corpo da requisição.
 */
class VerifyLegacyJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (!preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return response()->json(['error' => 'Token não fornecido'], 401);
        }

        try {
            $decoded = JWT::decode($matches[1], new Key(config('max.jwt_secret'), 'HS256'));
        } catch (ExpiredException) {
            return response()->json(['error' => 'Token expirado'], 401);
        } catch (SignatureInvalidException) {
            return response()->json(['error' => 'Token inválido'], 401);
        } catch (\Exception) {
            return response()->json(['error' => 'Token inválido'], 401);
        }

        // "Manter conectado" (token de 30 dias): vale só enquanto o usuário
        // está ativo e com a mesma senha — trocar a senha desconecta os aparelhos.
        if (!empty($decoded->lg)) {
            $senha = DB::table('usuarios')->where('id', $decoded->id ?? 0)->where('ativo', 1)->value('senha');
            if ($senha === null || AuthController::marcaSenha($senha) !== ($decoded->sv ?? '')) {
                return response()->json(['error' => 'Sessão encerrada. Entre novamente.', 'codigo' => 'sessao_encerrada'], 401);
            }
        }

        $request->attributes->set('max_jwt_token', $matches[1]);
        $request->attributes->set('max_user', $decoded);

        return $next($request);
    }
}
