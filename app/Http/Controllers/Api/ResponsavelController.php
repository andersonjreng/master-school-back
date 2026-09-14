<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Equivalente a api/responsaveis/*.php.
 */
class ResponsavelController extends Controller
{
    private const FOTO_BASE_URL = 'https://portalmasterschool.com.br/cepelc/api/';

    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas administradores podem listar os responsáveis.'], 403);
        }

        $rows = DB::select("
            SELECT
                u.id AS usuario_responsavel_id, u.nome_completo AS nome_responsavel, u.email AS email_responsavel,
                u.ativo AS responsavel_ativo, u.data_cadastro, r.id AS responsavel_registro_id, r.cpf, r.telefone,
                r.profissao, r.notificacao_whatsapp_ativa, r.endereco, r.bairro, r.cidade, r.uf, r.cep,
                GROUP_CONCAT(DISTINCT us.nome_completo ORDER BY us.nome_completo ASC SEPARATOR ' ; ') AS alunos_associados
            FROM responsaveis r
            JOIN usuarios u ON r.usuario_id = u.id
            LEFT JOIN aluno_responsavel ar ON r.id = ar.responsavel_id
            LEFT JOIN alunos a ON ar.aluno_id = a.id
            LEFT JOIN usuarios us ON a.usuario_id = us.id
            GROUP BY
                r.id, u.id, u.nome_completo, u.email, u.ativo, u.data_cadastro, r.cpf, r.telefone, r.profissao,
                r.notificacao_whatsapp_ativa, r.endereco, r.bairro, r.cidade, r.uf, r.cep
            ORDER BY u.nome_completo ASC
        ");

        $responsaveis = array_map(function ($row) {
            $arr = (array) $row;
            $arr['responsavel_ativo'] = (bool) $row->responsavel_ativo;
            $arr['notificacao_whatsapp_ativa'] = (bool) $row->notificacao_whatsapp_ativa;
            $arr['alunos_associados_array'] = $row->alunos_associados ? explode(' ; ', $row->alunos_associados) : [];
            unset($arr['alunos_associados']);

            return $arr;
        }, $rows);

        return response()->json(['success' => true, 'count' => count($responsaveis), 'data' => $responsaveis]);
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas administradores podem cadastrar responsáveis.'], 403);
        }

        $nomeCompleto = $request->input('nome_completo');
        $email = $request->input('email');
        $senhaBruta = $request->input('senha');
        $cpf = $request->input('cpf');
        $telefone = $request->input('telefone');
        $profissao = $request->input('profissao');
        $endereco = $request->input('endereco');
        $bairro = $request->input('bairro');
        $cidade = $request->input('cidade');
        $uf = $request->input('uf');
        $cep = $request->input('cep');
        $alunosIds = $request->input('alunos_ids', []);

        if (!$nomeCompleto || !$email || !$senhaBruta || !$cpf) {
            return response()->json(['error' => 'Os campos obrigatórios (nome_completo, email, senha, cpf) devem ser fornecidos.'], 400);
        }
        // Telefone é obrigatório: é o canal usado para notificação via WhatsApp.
        if (!$telefone) {
            return response()->json(['error' => 'O campo telefone é obrigatório.'], 400);
        }

        try {
            [$responsavelId, $usuarioId] = DB::transaction(function () use (
                $nomeCompleto, $email, $senhaBruta, $cpf, $profissao, $telefone,
                $endereco, $bairro, $cidade, $uf, $cep, $alunosIds
            ) {
                $usuarioId = DB::table('usuarios')->insertGetId([
                    'nome_completo' => $nomeCompleto,
                    'email'         => $email,
                    'senha'         => Hash::make($senhaBruta),
                    'data_cadastro' => now(),
                    'ativo'         => 1,
                ]);

                try {
                    $responsavelId = DB::table('responsaveis')->insertGetId([
                        'usuario_id' => $usuarioId,
                        'cpf'        => $cpf,
                        'profissao'  => $profissao,
                        'telefone'   => $telefone,
                        'endereco'   => $endereco,
                        'bairro'     => $bairro,
                        'cidade'     => $cidade,
                        'uf'         => $uf,
                        'cep'        => $cep,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    if ((int) $e->errorInfo[1] === 1062) {
                        throw new \Exception('CPF já cadastrado no sistema.');
                    }
                    throw $e;
                }

                // ID 5 = Responsável (ver tabela funcoes).
                DB::table('usuario_funcao')->insert(['usuario_id' => $usuarioId, 'funcao_id' => 5]);

                foreach ($alunosIds as $alunoId) {
                    if (!is_numeric($alunoId)) {
                        continue;
                    }
                    DB::table('aluno_responsavel')->insert(['aluno_id' => $alunoId, 'responsavel_id' => $responsavelId]);
                }

                return [$responsavelId, $usuarioId];
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha no cadastro transacional do responsável.', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success'                  => 'Responsável e vínculos com alunos cadastrados com sucesso.',
            'responsavel_id'           => $responsavelId,
            'usuario_id'               => $usuarioId,
            'cpf'                      => $cpf,
            'telefone'                 => $telefone,
            'alunos_vinculados_count'  => count($alunosIds),
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas administradores podem editar responsáveis.'], 403);
        }

        $responsavelId = $request->input('responsavel_id');
        if (!$responsavelId) {
            return response()->json(['error' => 'O campo responsavel_id é obrigatório.'], 400);
        }

        $responsavelAtual = DB::table('responsaveis')->where('id', $responsavelId)->first(['usuario_id', 'telefone']);
        if (!$responsavelAtual) {
            return response()->json(['error' => 'Responsável não encontrado.'], 404);
        }
        $usuarioId = $responsavelAtual->usuario_id;

        // Ativar notificação via WhatsApp exige um telefone cadastrado (é o canal de envio).
        if ($request->boolean('notificacao_whatsapp_ativa') && $request->has('notificacao_whatsapp_ativa')) {
            $telefoneFinal = $request->input('telefone', $responsavelAtual->telefone);
            if (!$telefoneFinal) {
                return response()->json(['error' => 'Não é possível ativar a notificação via WhatsApp sem um telefone cadastrado.'], 400);
            }
        }

        $camposUsuario = [];
        if ($request->has('nome_completo')) {
            $camposUsuario['nome_completo'] = $request->input('nome_completo');
        }
        if ($request->has('email')) {
            $camposUsuario['email'] = $request->input('email');
        }
        if ($request->has('senha')) {
            $camposUsuario['senha'] = Hash::make($request->input('senha'));
        }
        if ($request->has('ativo')) {
            $camposUsuario['ativo'] = $request->input('ativo');
        }

        $camposResp = [];
        foreach (['cpf', 'profissao', 'telefone'] as $campo) {
            if ($request->has($campo)) {
                $camposResp[$campo] = $request->input($campo);
            }
        }
        if ($request->has('notificacao_whatsapp_ativa')) {
            $camposResp['notificacao_whatsapp_ativa'] = $request->boolean('notificacao_whatsapp_ativa') ? 1 : 0;
        }
        // Endereço — usado pra emissão de boleto (Financeiro > Convênio Bancário).
        foreach (['endereco', 'bairro', 'cidade', 'uf', 'cep'] as $campo) {
            if ($request->has($campo)) {
                $camposResp[$campo] = $request->input($campo);
            }
        }

        if (!$camposUsuario && !$camposResp) {
            return response()->json(['error' => 'Nenhum campo para atualizar.'], 400);
        }

        try {
            DB::transaction(function () use ($camposUsuario, $camposResp, $usuarioId, $responsavelId) {
                if ($camposUsuario) {
                    DB::table('usuarios')->where('id', $usuarioId)->update($camposUsuario);
                }
                if ($camposResp) {
                    DB::table('responsaveis')->where('id', $responsavelId)->update($camposResp);
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha ao atualizar responsável.', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => 'Responsável atualizado com sucesso.']);
    }

    public function dependentes(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = $request->query('usuario_id');
        if (!$usuarioId) {
            return response()->json(['error' => 'Parâmetro usuario_id é obrigatório.'], 400);
        }

        // Só admin/diretoria podem consultar dependentes de outro usuário; o restante só vê os próprios.
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $ehPrivilegiado = !empty(array_intersect(['Administrador', 'Diretoria'], $funcoes));
        if (!$ehPrivilegiado && (int) ($jwtUser->id ?? 0) !== (int) $usuarioId) {
            return response()->json(['error' => 'Acesso negado. Você só pode consultar seus próprios dependentes.'], 403);
        }

        $rows = DB::select('
            SELECT
                a.id AS aluno_id, a.matricula, a.data_nascimento, a.status_matricula, a.foto_path,
                u_aluno.nome_completo AS nome_aluno, u_aluno.ativo, ar.parentesco, ar.principal,
                ta.turma_id, t.nome_turma
            FROM usuarios u_resp
            JOIN responsaveis r ON u_resp.id = r.usuario_id
            JOIN aluno_responsavel ar ON r.id = ar.responsavel_id
            JOIN alunos a ON ar.aluno_id = a.id
            JOIN usuarios u_aluno ON a.usuario_id = u_aluno.id
            LEFT JOIN turma_aluno ta ON ta.aluno_id = a.id
            LEFT JOIN turmas t ON ta.turma_id = t.id
            WHERE u_resp.id = ?
            ORDER BY u_aluno.nome_completo ASC
        ', [$usuarioId]);

        $dependentes = array_map(function ($row) {
            $arr = (array) $row;
            $arr['principal'] = (bool) $row->principal;
            $arr['ativo'] = (bool) $row->ativo;
            $arr['foto_path'] = !empty($row->foto_path) ? self::FOTO_BASE_URL . $row->foto_path : null;

            return $arr;
        }, $rows);

        return response()->json([
            'success'                => true,
            'responsavel_usuario_id' => $usuarioId,
            'total_dependentes'      => count($dependentes),
            'data'                   => $dependentes,
        ]);
    }

    public function responsaveisPorAluno(Request $request): JsonResponse
    {
        $alunoId = $request->query('aluno_id');
        if (!$alunoId) {
            return response()->json(['error' => 'O ID do aluno é obrigatório.'], 400);
        }

        $rows = DB::select('
            SELECT
                u.id AS usuario_responsavel_id, u.nome_completo AS nome_responsavel, u.email AS email_responsavel,
                u.ativo AS responsavel_ativo, u.data_cadastro, r.id AS responsavel_registro_id, r.cpf, r.telefone, r.profissao
            FROM responsaveis r
            JOIN usuarios u ON r.usuario_id = u.id
            JOIN aluno_responsavel ar ON r.id = ar.responsavel_id
            WHERE ar.aluno_id = ?
            ORDER BY u.nome_completo ASC
        ', [(int) $alunoId]);

        $responsaveis = array_map(function ($row) {
            $arr = (array) $row;
            $arr['responsavel_ativo'] = (bool) $row->responsavel_ativo;

            return $arr;
        }, $rows);

        return response()->json([
            'success'  => true,
            'aluno_id' => (int) $alunoId,
            'count'    => count($responsaveis),
            'data'     => $responsaveis,
        ]);
    }
}
