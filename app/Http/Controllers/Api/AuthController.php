<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use App\Services\Sistema\PermissaoService;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Equivalente a api/auth/login.php.
 */
class AuthController extends Controller
{
    public function __construct(private LogSistemaService $log, private PermissaoService $permissoes)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $email = $request->input('email', '');
        $senha = $request->input('senha', '');

        if (!$email || !$senha) {
            return response()->json(['error' => 'Email e senha são obrigatórios'], 400);
        }

        $colunas = ['id', 'nome_completo', 'email', 'senha'];
        if (Schema::hasColumn('usuarios', 'acesso_portal')) {
            $colunas[] = 'acesso_portal';
        }
        $user = DB::table('usuarios')->where('email', $email)->where('ativo', 1)->first($colunas);

        if (!$user || !$user->senha || !Hash::check($senha, $user->senha)) {
            $this->log->registrar(
                $request, $user->id ?? null, $email, 'LOGIN_FALHA',
                '/api/auth/login', 'POST', "Tentativa de login falhou para: $email", 401,
                ['email' => $email, 'motivo' => $user ? 'senha_incorreta' : 'usuario_inexistente_ou_inativo']
            );

            return response()->json(['error' => 'Credenciais inválidas'], 401);
        }

        // Aluno cadastrado sem acesso ao portal (a secretaria libera na ficha).
        if (isset($user->acesso_portal) && !$user->acesso_portal) {
            $this->log->registrar(
                $request, $user->id, $email, 'LOGIN_FALHA',
                '/api/auth/login', 'POST', "Login bloqueado (sem acesso ao portal): $email", 403,
                ['email' => $email, 'motivo' => 'sem_acesso_portal']
            );

            return response()->json(['error' => 'Este usuário não tem acesso ao portal. Procure a secretaria da escola.'], 403);
        }

        $roles = DB::table('usuario_funcao as uf')
            ->join('funcoes as f', 'uf.funcao_id', '=', 'f.id')
            ->where('uf.usuario_id', $user->id)
            ->pluck('f.nome_funcao')
            ->all();

        // "sid" agrupa na auditoria tudo o que foi feito neste login (sessão).
        $sid = bin2hex(random_bytes(10));
        $request->attributes->set(LogSistemaService::ATRIBUTO_SESSAO, $sid);

        $jwt = self::emitirToken($user, $roles, $sid, $request->boolean('manter_conectado'));

        DB::table('usuarios')->where('id', $user->id)->update(['ultimo_login' => now()]);

        $this->log->registrar(
            $request, $user->id, $user->nome_completo, 'LOGIN_SUCESSO',
            '/api/auth/login', 'POST', "Login realizado com sucesso por: {$user->nome_completo} ($email)", 200,
            ['email' => $email, 'perfis' => $roles]
        );

        return response()->json([
            'token' => $jwt,
            'id' => $user->id,
            'username' => $user->nome_completo,
            'roles' => $roles,
            'permissoes' => $this->permissoes->efetivas($roles),
        ]);
    }

    /**
     * "Manter conectado": troca o token de 30 dias por um novo (mesma sessão),
     * com perfis atualizados. O front chama ao abrir o app, no máximo uma vez
     * por dia — quem usa toda semana nunca precisa entrar de novo. Sessão
     * comum (24 h) não renova.
     */
    public function renovar(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        if (empty($jwtUser->lg)) {
            return response()->json(['error' => 'Sessão sem "manter conectado".'], 400);
        }

        // O middleware já conferiu que a senha não mudou e o usuário está ativo.
        $user = DB::table('usuarios')->where('id', $jwtUser->id)->where('ativo', 1)->first(['id', 'nome_completo', 'email', 'senha']);
        if (!$user) {
            return response()->json(['error' => 'Sessão encerrada.', 'codigo' => 'sessao_encerrada'], 401);
        }
        $roles = DB::table('usuario_funcao as uf')->join('funcoes as f', 'uf.funcao_id', '=', 'f.id')
            ->where('uf.usuario_id', $user->id)->pluck('f.nome_funcao')->all();

        return response()->json([
            'token' => self::emitirToken($user, $roles, (string) ($jwtUser->sid ?? bin2hex(random_bytes(10))), true),
            'roles' => $roles,
            'permissoes' => $this->permissoes->efetivas($roles),
        ]);
    }

    /** Dias de validade do token com "manter conectado" (renovado a cada uso). */
    public const DIAS_MANTER_CONECTADO = 30;

    /**
     * JWT da sessão: 24 h, ou 30 dias com "manter conectado" — nesse caso leva
     * "lg" e "sv" (marca da senha atual), e o middleware derruba o token se a
     * senha mudar ou o usuário for desativado.
     */
    private static function emitirToken(object $user, array $roles, string $sid, bool $manterConectado): string
    {
        $payload = [
            'id' => $user->id,
            'nome' => $user->nome_completo,
            'email' => $user->email,
            'funcoes' => $roles,
            'sid' => $sid,
            'iat' => time(),
            'exp' => time() + ($manterConectado ? self::DIAS_MANTER_CONECTADO * 86400 : 86400),
        ];
        if ($manterConectado) {
            $payload['lg'] = 1;
            $payload['sv'] = self::marcaSenha($user->senha);
        }

        return JWT::encode($payload, config('max.jwt_secret'), 'HS256');
    }

    /** Marca curta do hash da senha: muda quando a senha muda, sem expor o hash. */
    public static function marcaSenha(?string $hash): string
    {
        return substr(hash('sha256', (string) $hash), 0, 16);
    }

    /**
     * Permissões atuais de quem está logado. O front chama ao abrir o app,
     * então exceções novas valem sem precisar logar de novo.
     */
    public function permissoes(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        return response()->json(['roles' => $funcoes, 'permissoes' => $this->permissoes->efetivas($funcoes)]);
    }
}
