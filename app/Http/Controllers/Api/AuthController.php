<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Equivalente a api/auth/login.php.
 */
class AuthController extends Controller
{
    public function __construct(private LogSistemaService $log)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $email = $request->input('email', '');
        $senha = $request->input('senha', '');

        if (!$email || !$senha) {
            return response()->json(['error' => 'Email e senha são obrigatórios'], 400);
        }

        $user = DB::table('usuarios')->where('email', $email)->where('ativo', 1)
            ->first(['id', 'nome_completo', 'email', 'senha']);

        if (!$user || !Hash::check($senha, $user->senha)) {
            $this->log->registrar(
                $request, $user->id ?? null, $email, 'LOGIN_FALHA',
                '/api/auth/login', 'POST', "Tentativa de login falhou para: $email", 401
            );

            return response()->json(['error' => 'Credenciais inválidas'], 401);
        }

        $roles = DB::table('usuario_funcao as uf')
            ->join('funcoes as f', 'uf.funcao_id', '=', 'f.id')
            ->where('uf.usuario_id', $user->id)
            ->pluck('f.nome_funcao')
            ->all();

        $payload = [
            'id' => $user->id,
            'nome' => $user->nome_completo,
            'email' => $user->email,
            'funcoes' => $roles,
            'iat' => time(),
            'exp' => time() + 86400, // 24 horas
        ];

        $jwt = JWT::encode($payload, config('max.jwt_secret'), 'HS256');

        DB::table('usuarios')->where('id', $user->id)->update(['ultimo_login' => now()]);

        $this->log->registrar(
            $request, $user->id, $user->nome_completo, 'LOGIN_SUCESSO',
            '/api/auth/login', 'POST', "Login realizado com sucesso por: {$user->nome_completo} ($email)", 200
        );

        return response()->json([
            'token' => $jwt,
            'id' => $user->id,
            'username' => $user->nome_completo,
            'roles' => $roles,
        ]);
    }
}
