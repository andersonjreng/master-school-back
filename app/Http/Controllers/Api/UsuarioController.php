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
 * login de todo mundo. Aqui a checagem "Apenas administradores" ficou de fato
 * aplicada, como create/update/patch_status já faziam.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Requer função de Administração.'], 403);
        }

        $rows = DB::table('usuarios as u')
            ->leftJoin('usuario_funcao as uf', 'u.id', '=', 'uf.usuario_id')
            ->leftJoin('funcoes as f', 'uf.funcao_id', '=', 'f.id')
            ->orderBy('u.id')->orderBy('f.nome_funcao')
            ->get(['u.id', 'u.nome_completo', 'u.email', 'u.data_cadastro', 'u.ativo', 'u.ultimo_login', 'f.nome_funcao']);

        $users = [];
        foreach ($rows as $row) {
            if (!isset($users[$row->id])) {
                $users[$row->id] = [
                    'id' => $row->id,
                    'nome_completo' => $row->nome_completo,
                    'email' => $row->email,
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
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Requer função de Administração.'], 403);
        }

        $nomeCompleto = $request->input('nome_completo');
        $email = $request->input('email');
        $senhaBruta = $request->input('senha');
        $funcoesIds = $request->input('funcoes_ids', []);

        if (!$nomeCompleto || !$email || !$senhaBruta || empty($funcoesIds)) {
            return response()->json(['error' => 'Campos obrigatórios: nome_completo, email, senha e ao menos uma função.'], 400);
        }

        try {
            $usuarioId = DB::transaction(function () use ($nomeCompleto, $email, $senhaBruta, $funcoesIds) {
                $usuarioId = DB::table('usuarios')->insertGetId([
                    'nome_completo' => $nomeCompleto,
                    'email' => $email,
                    'senha' => Hash::make($senhaBruta),
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
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Requer função de Administração.'], 403);
        }

        $usuarioId = $request->input('usuario_id');
        if (!$usuarioId) {
            return response()->json(['error' => 'O campo usuario_id é obrigatório.'], 400);
        }

        $campos = [];
        if ($request->has('nome_completo')) {
            $campos['nome_completo'] = $request->input('nome_completo');
        }
        if ($request->has('email')) {
            $campos['email'] = $request->input('email');
        }
        if ($request->has('senha')) {
            $campos['senha'] = Hash::make($request->input('senha'));
        }
        if ($request->has('ativo')) {
            $campos['ativo'] = $request->input('ativo');
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
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas administradores podem alterar o status de um usuário.'], 403);
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

        $atual = DB::table('usuarios')->where('id', $usuarioId)->value('ativo');
        DB::table('usuarios')->where('id', $usuarioId)->update(['ativo' => $ativo]);

        if ((int) $atual === (int) $ativo) {
            return response()->json(['info' => "Usuário ID $usuarioId encontrado, mas o status já estava configurado."]);
        }

        $statusDesc = $ativo == 1 ? 'Ativo' : 'Inativo';

        return response()->json(['success' => "Status do usuário ID $usuarioId atualizado para $statusDesc."]);
    }
}
