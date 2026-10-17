<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Equivalente a api/admin/{get_users,create_user,update_user,patch_status_usuario}.php.
 *
 * Fix vs. legado: get_users.php não tinha NENHUMA verificação de papel aplicada
 * (só um comentário "Opcional: verificar se é Admin", nunca ativado) — qualquer
 * usuário autenticado (professor, responsável) conseguia listar email e último
 * login de todo mundo. Aqui a checagem "Apenas a administração/direção" ficou de fato
 * aplicada, como create/update/patch_status já faziam.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Papeis::ehGestor($funcoes)) {
            return response()->json(['error' => 'Acesso negado. Requer função de Administração.'], 403);
        }

        $rows = DB::table('usuarios as u')
            ->leftJoin('usuario_funcao as uf', 'u.id', '=', 'uf.usuario_id')
            ->leftJoin('funcoes as f', 'uf.funcao_id', '=', 'f.id')
            ->orderBy('u.id')->orderBy('f.nome_funcao')
            ->get(['u.id', 'u.nome_completo', 'u.email', 'u.telefone', 'u.data_cadastro', 'u.ativo', 'u.ultimo_login', 'f.nome_funcao']);

        $users = [];
        foreach ($rows as $row) {
            if (!isset($users[$row->id])) {
                $users[$row->id] = [
                    'id' => $row->id,
                    'nome_completo' => $row->nome_completo,
                    'email' => $row->email,
                    'telefone' => $row->telefone,
                    'data_cadastro' => $row->data_cadastro,
                    'ativo' => (bool) $row->ativo,
                    'ultimo_login' => $row->ultimo_login,
                    'funcoes' => [],
                ];
            }
            if ($row->nome_funcao !== null) {
                $users[$row->id]['funcoes'][] = $row->nome_funcao;
            }
        }

        return response()->json(array_values($users));
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Papeis::ehGestor($funcoes)) {
            return response()->json(['error' => 'Acesso negado. Requer função de Administração.'], 403);
        }

        $nomeCompleto = $request->input('nome_completo');
        $email = $request->input('email');
        $senhaBruta = $request->input('senha');
        $funcoesIds = $request->input('funcoes_ids', []);

        if ($erro = $this->checarPapelAdministrador($funcoes, null, (array) $funcoesIds)) {
            return response()->json(['error' => $erro], 403);
        }

        if (!$nomeCompleto || !$email || !$senhaBruta || empty($funcoesIds)) {
            return response()->json(['error' => 'Campos obrigatórios: nome_completo, email, senha e ao menos uma função.'], 400);
        }
        $v = app(\App\Services\Cadastro\CadastroValidacaoService::class);
        if ($erro = $v->validarEmail($email) ?? $v->validarSenha($senhaBruta) ?? $v->validarTelefone($request->input('telefone'), false)) {
            return response()->json(['error' => $erro], 422);
        }
        $telefone = $v->telefone($request->input('telefone'));

        try {
            $usuarioId = DB::transaction(function () use ($nomeCompleto, $email, $senhaBruta, $funcoesIds, $telefone) {
                $usuarioId = DB::table('usuarios')->insertGetId([
                    'nome_completo' => $nomeCompleto,
                    'email' => $email,
                    'senha' => Hash::make($senhaBruta),
                    'telefone' => $telefone,
                    'data_cadastro' => now(),
                    'ativo' => 1,
                ]);

                foreach ($funcoesIds as $funcaoId) {
                    if (!is_numeric($funcaoId)) {
                        continue;
                    }
                    DB::table('usuario_funcao')->insert(['usuario_id' => $usuarioId, 'funcao_id' => $funcaoId]);
                }

                return $usuarioId;
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha ao processar cadastro.', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => 'Usuário criado com sucesso.', 'usuario_id' => $usuarioId, 'email' => $email], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Papeis::ehGestor($funcoes)) {
            return response()->json(['error' => 'Acesso negado. Requer função de Administração.'], 403);
        }

        $usuarioId = $request->input('usuario_id');
        if (!$usuarioId) {
            return response()->json(['error' => 'O campo usuario_id é obrigatório.'], 400);
        }
        if ($erro = $this->checarPapelAdministrador($funcoes, (int) $usuarioId, $request->has('funcoes_ids') ? (array) $request->input('funcoes_ids') : null)) {
            return response()->json(['error' => $erro], 403);
        }

        $campos = [];
        if ($request->has('nome_completo')) {
            $campos['nome_completo'] = $request->input('nome_completo');
        }
        if ($request->has('email')) {
            $campos['email'] = $request->input('email');
        }
        if ($request->filled('senha')) {
            $campos['senha'] = Hash::make($request->input('senha'));
        }
        if ($request->has('ativo')) {
            $campos['ativo'] = $request->input('ativo');
        }
        $v = app(\App\Services\Cadastro\CadastroValidacaoService::class);
        if ($request->has('telefone')) {
            $campos['telefone'] = $v->telefone($request->input('telefone'));
        }
        $erro = ($request->has('email') ? $v->validarEmail($request->input('email'), (int) $usuarioId) : null)
            ?? ($request->filled('senha') ? $v->validarSenha($request->input('senha')) : null)
            ?? ($request->has('telefone') ? $v->validarTelefone($request->input('telefone'), false) : null);
        if ($erro) {
            return response()->json(['error' => $erro], 422);
        }

        if (empty($campos) && !$request->has('funcoes_ids')) {
            return response()->json(['error' => 'Nenhum campo para atualizar.'], 400);
        }
        if (!DB::table('usuarios')->where('id', $usuarioId)->exists()) {
            return response()->json(['error' => 'Usuário não encontrado.'], 404);
        }

        try {
            DB::transaction(function () use ($campos, $usuarioId, $request) {
                if (!empty($campos)) {
                    DB::table('usuarios')->where('id', $usuarioId)->update($campos);
                }

                $funcoesIds = $request->input('funcoes_ids');
                if (is_array($funcoesIds)) {
                    DB::table('usuario_funcao')->where('usuario_id', $usuarioId)->delete();
                    foreach ($funcoesIds as $funcaoId) {
                        if (!is_numeric($funcaoId)) {
                            continue;
                        }
                        DB::table('usuario_funcao')->insert(['usuario_id' => $usuarioId, 'funcao_id' => $funcaoId]);
                    }
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha ao atualizar usuário.', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => 'Usuário atualizado com sucesso.']);
    }

    public function patchStatus(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Papeis::ehGestor($funcoes)) {
            return response()->json(['error' => 'Acesso negado. Apenas a administração/direção podem alterar o status de um usuário.'], 403);
        }

        $usuarioId = $request->input('usuario_id');
        $ativo = $request->input('ativo');

        if (!$usuarioId || !is_numeric($usuarioId)) {
            return response()->json(['error' => 'ID do usuário é obrigatório.'], 400);
        }
        if ($ativo === null || !in_array($ativo, [0, 1], true)) {
            return response()->json(['error' => 'Valor de "ativo" inválido. Use 1 para Ativo ou 0 para Inativo.'], 400);
        }

        if (!DB::table('usuarios')->where('id', $usuarioId)->exists()) {
            return response()->json(['error' => "Usuário ID $usuarioId não encontrado."], 404);
        }
        if ($erro = $this->checarPapelAdministrador($funcoes, (int) $usuarioId, null)) {
            return response()->json(['error' => $erro], 403);
        }

        $atual = DB::table('usuarios')->where('id', $usuarioId)->value('ativo');
        DB::table('usuarios')->where('id', $usuarioId)->update(['ativo' => $ativo]);

        if ((int) $atual === (int) $ativo) {
            return response()->json(['info' => "Usuário ID $usuarioId encontrado, mas o status já estava configurado."]);
        }

        $statusDesc = $ativo == 1 ? 'Ativo' : 'Inativo';

        return response()->json(['success' => "Status do usuário ID $usuarioId atualizado para $statusDesc."]);
    }

    /**
     * A Diretoria gerencia usuários, mas não mexe em quem é Administrador nem
     * concede/retira esse papel — só um Administrador pode.
     */
    private function checarPapelAdministrador(array $funcoesQuemFaz, ?int $alvoId, ?array $novasFuncoesIds): ?string
    {
        if (\App\Support\Papeis::ehAdministrador($funcoesQuemFaz)) {
            return null;
        }
        $adminId = (int) DB::table('funcoes')->where('nome_funcao', 'Administrador')->value('id');
        $alvoEhAdmin = $alvoId && DB::table('usuario_funcao')->where('usuario_id', $alvoId)->where('funcao_id', $adminId)->exists();
        if ($alvoEhAdmin) {
            return 'Somente um Administrador pode alterar um usuário Administrador.';
        }
        if ($novasFuncoesIds !== null && in_array($adminId, array_map('intval', $novasFuncoesIds), true)) {
            return 'Somente um Administrador pode conceder o papel Administrador.';
        }
        return null;
    }
}
