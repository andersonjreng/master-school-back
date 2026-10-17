<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlunoResponsavelVinculoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Equivalente a api/responsaveis/*.php.
 */
class ResponsavelController extends Controller
{

    public function __construct(
        private AlunoResponsavelVinculoService $vinculos,
        private \App\Services\Cadastro\CadastroValidacaoService $validacao,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Papeis::ehGestor($funcoes)) {
            return response()->json(['error' => 'Acesso negado. Apenas a administração/direção podem listar os responsáveis.'], 403);
        }

        $rows = DB::select("
            SELECT
                u.id AS usuario_responsavel_id, u.nome_completo AS nome_responsavel, u.email AS email_responsavel,
                u.ativo AS responsavel_ativo, u.data_cadastro, r.id AS responsavel_registro_id, r.cpf, r.telefone,
                r.profissao, r.notificacao_whatsapp_ativa, r.endereco, r.numero, r.complemento, r.bairro, r.cidade, r.uf, r.cep,
                GROUP_CONCAT(DISTINCT us.nome_completo ORDER BY us.nome_completo ASC SEPARATOR ' ; ') AS alunos_associados,
                GROUP_CONCAT(DISTINCT a.id SEPARATOR ',') AS alunos_ids
            FROM responsaveis r
            JOIN usuarios u ON r.usuario_id = u.id
            LEFT JOIN aluno_responsavel ar ON r.id = ar.responsavel_id
            LEFT JOIN alunos a ON ar.aluno_id = a.id
            LEFT JOIN usuarios us ON a.usuario_id = us.id
            GROUP BY
                r.id, u.id, u.nome_completo, u.email, u.ativo, u.data_cadastro, r.cpf, r.telefone, r.profissao,
                r.notificacao_whatsapp_ativa, r.endereco, r.numero, r.complemento, r.bairro, r.cidade, r.uf, r.cep
            ORDER BY u.nome_completo ASC
        ");

        $responsaveis = array_map(function ($row) {
            $arr = (array) $row;
            $arr['responsavel_ativo'] = (bool) $row->responsavel_ativo;
            $arr['notificacao_whatsapp_ativa'] = (bool) $row->notificacao_whatsapp_ativa;
            $arr['alunos_associados_array'] = $row->alunos_associados ? explode(' ; ', $row->alunos_associados) : [];
            $arr['alunos_ids'] = $row->alunos_ids ? array_map('intval', explode(',', $row->alunos_ids)) : [];
            $arr['pendencias_cadastro'] = $this->pendencias($arr);
            unset($arr['alunos_associados']);

            return $arr;
        }, $rows);

        return response()->json(['success' => true, 'count' => count($responsaveis), 'data' => $responsaveis]);
    }

    /** O que falta para o responsável receber boleto e WhatsApp. */
    private function pendencias(array $r): array
    {
        $p = [];
        if (!$this->validacao->cpfValido($r['cpf'] ?? null)) {
            $p[] = 'CPF';
        }
        if (!$this->validacao->telefoneValido($r['telefone'] ?? null)) {
            $p[] = 'telefone';
        }
        if ($this->validacao->validarEndereco($r, true)) {
            $p[] = 'endereço';
        }
        return $p;
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Papeis::ehGestor($funcoes)) {
            return response()->json(['error' => 'Acesso negado. Apenas a administração/direção podem cadastrar responsáveis.'], 403);
        }

        $v = $this->validacao;
        $nomeCompleto = $v->texto($request->input('nome_completo'));
        $email = $v->texto($request->input('email'));
        $senhaBruta = (string) $request->input('senha');
        $alunosIds = array_filter((array) $request->input('alunos_ids', []), 'is_numeric');

        $erro = (!$nomeCompleto ? 'Informe o nome completo.' : null)
            ?? $v->validarCpf('responsaveis', $request->input('cpf'), true)
            ?? $v->validarEmail($email)
            ?? $v->validarSenha($senhaBruta)
            ?? $v->validarTelefone($request->input('telefone'), true)
            ?? $v->validarEndereco($request->all(), true);
        if ($erro) {
            return response()->json(['error' => $erro], 422);
        }

        $dados = [
            'cpf'       => $v->formatarCpf($request->input('cpf')),
            'telefone'  => $v->telefone($request->input('telefone')),
            'profissao' => $v->texto($request->input('profissao')),
            'notificacao_whatsapp_ativa' => $request->boolean('notificacao_whatsapp_ativa') ? 1 : 0,
        ] + $v->endereco($request->all());

        try {
            [$responsavelId, $usuarioId] = DB::transaction(function () use ($nomeCompleto, $email, $senhaBruta, $dados, $alunosIds) {
                $usuarioId = DB::table('usuarios')->insertGetId([
                    'nome_completo' => $nomeCompleto,
                    'email'         => $email,
                    'senha'         => Hash::make($senhaBruta),
                    'telefone'      => $dados['telefone'],
                    'data_cadastro' => now(),
                    'ativo'         => 1,
                ]);

                $responsavelId = DB::table('responsaveis')->insertGetId(['usuario_id' => $usuarioId] + $dados);

                // ID 5 = Responsável (ver tabela funcoes).
                DB::table('usuario_funcao')->insert(['usuario_id' => $usuarioId, 'funcao_id' => 5]);

                foreach ($alunosIds as $alunoId) {
                    DB::table('aluno_responsavel')->insertOrIgnore(['aluno_id' => $alunoId, 'responsavel_id' => $responsavelId, 'parentesco' => '']);
                }

                return [$responsavelId, $usuarioId];
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => $v->mensagemDuplicidade($e) ?? 'Falha ao cadastrar o responsável.', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success'                  => 'Responsável cadastrado com sucesso.',
            'responsavel_id'           => $responsavelId,
            'usuario_id'               => $usuarioId,
            'nome'                     => $nomeCompleto,
            'cpf'                      => $dados['cpf'],
            'telefone'                 => $dados['telefone'],
            'alunos_vinculados_count'  => count($alunosIds),
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Papeis::ehGestor($funcoes)) {
            return response()->json(['error' => 'Acesso negado. Apenas a administração/direção podem editar responsáveis.'], 403);
        }

        $responsavelId = (int) $request->input('responsavel_id');
        if (!$responsavelId) {
            return response()->json(['error' => 'O campo responsavel_id é obrigatório.'], 400);
        }

        $atual = DB::table('responsaveis')->where('id', $responsavelId)->first();
        if (!$atual) {
            return response()->json(['error' => 'Responsável não encontrado.'], 404);
        }
        $usuarioId = (int) $atual->usuario_id;
        $v = $this->validacao;

        // Só valida o que veio. CPF/endereço antigos fora do padrão não travam
        // a edição de outros campos — mas, se forem enviados, têm que estar certos.
        $erro = null;
        if ($request->has('nome_completo') && !$v->texto($request->input('nome_completo'))) {
            $erro = 'Informe o nome completo.';
        }
        if (!$erro && $request->has('email')) {
            $erro = $v->validarEmail($request->input('email'), $usuarioId);
        }
        if (!$erro && $request->filled('senha')) {
            $erro = $v->validarSenha($request->input('senha'));
        }
        if (!$erro && $request->has('cpf') && $v->formatarCpf($request->input('cpf')) !== $atual->cpf) {
            $erro = $v->validarCpf('responsaveis', $request->input('cpf'), true, $responsavelId);
        }
        if (!$erro && $request->has('telefone')) {
            $erro = $v->validarTelefone($request->input('telefone'), true);
        }
        $camposEndereco = ['cep', 'endereco', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];
        $veioEndereco = collect($camposEndereco)->contains(fn ($c) => $request->has($c));
        if (!$erro && $veioEndereco) {
            $erro = $v->validarEndereco(array_merge((array) $atual, $request->only($camposEndereco)), true);
        }
        if (!$erro && $request->boolean('notificacao_whatsapp_ativa')) {
            $tel = $request->has('telefone') ? $request->input('telefone') : $atual->telefone;
            if (!$v->telefoneValido($tel)) {
                $erro = 'Não é possível ativar a notificação via WhatsApp sem um telefone válido.';
            }
        }
        if ($erro) {
            return response()->json(['error' => $erro], 422);
        }

        $camposUsuario = [];
        if ($request->has('nome_completo')) {
            $camposUsuario['nome_completo'] = $v->texto($request->input('nome_completo'));
        }
        if ($request->has('email')) {
            $camposUsuario['email'] = $v->texto($request->input('email'));
        }
        if ($request->filled('senha')) {
            $camposUsuario['senha'] = Hash::make($request->input('senha'));
        }
        if ($request->has('ativo')) {
            $camposUsuario['ativo'] = $request->boolean('ativo') ? 1 : 0;
        }

        $camposResp = [];
        if ($request->has('cpf')) {
            $camposResp['cpf'] = $v->formatarCpf($request->input('cpf'));
        }
        if ($request->has('telefone')) {
            $camposResp['telefone'] = $camposUsuario['telefone'] = $v->telefone($request->input('telefone'));
        }
        if ($request->has('profissao')) {
            $camposResp['profissao'] = $v->texto($request->input('profissao'));
        }
        if ($request->has('notificacao_whatsapp_ativa')) {
            $camposResp['notificacao_whatsapp_ativa'] = $request->boolean('notificacao_whatsapp_ativa') ? 1 : 0;
        }
        if ($veioEndereco) {
            $camposResp += array_intersect_key($v->endereco($request->all()), array_flip(array_keys($request->only($camposEndereco))));
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
            return response()->json(['error' => $v->mensagemDuplicidade($e) ?? 'Falha ao atualizar responsável.', 'message' => $e->getMessage()], 422);
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
            $arr['foto_path'] = \App\Services\Alunos\FotoAlunoService::url($row->foto_path);

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

    /**
     * Equivalente a api/admin/post_aluno_responsavel.php — vincula vários
     * alunos a UM responsável (payload fixa responsavel_id, varia alunos_ids).
     */
    public function vincularAlunos(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Papeis::ehGestor($funcoes)) {
            return response()->json(['error' => 'Acesso negado. Apenas a administração/direção podem vincular responsáveis a alunos.'], 403);
        }

        $responsavelId = $request->input('responsavel_id');
        $alunosIds = $request->input('alunos_ids', []);

        if (!$responsavelId || !is_numeric($responsavelId)) {
            return response()->json(['error' => 'O ID do responsável (responsavel_id) deve ser fornecido.'], 400);
        }
        if (empty($alunosIds) || !is_array($alunosIds)) {
            return response()->json(['error' => 'A lista de IDs dos alunos (alunos_ids) deve ser fornecida como um array não vazio.'], 400);
        }

        $alunosIdsValidos = array_values(array_filter($alunosIds, 'is_numeric'));
        if (empty($alunosIdsValidos)) {
            return response()->json(['error' => 'Nenhum aluno válido fornecido para vincular.'], 400);
        }

        [$vinculosCriados, $avisos] = $this->vinculos->criar((int) $responsavelId, $alunosIdsValidos, porResponsavel: true);

        if ($vinculosCriados > 0) {
            return response()->json([
                'success' => "$vinculosCriados alunos vinculados ao Responsável ID $responsavelId com sucesso.",
                'responsavel_id' => (int) $responsavelId,
                'vinculos_criados' => $vinculosCriados,
                'total_solicitado' => count($alunosIdsValidos),
                'avisos' => $avisos,
            ], 201);
        }
        if (!empty($avisos)) {
            return response()->json(['success' => 'Nenhum novo vínculo criado.', 'responsavel_id' => (int) $responsavelId, 'avisos' => $avisos]);
        }

        return response()->json(['error' => 'Nenhuma operação válida executada.'], 400);
    }

    /** Painel da família (home do responsável) — só os próprios dependentes. */
    public function painel(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (empty(array_intersect(['Responsável', 'Responsavel'], $funcoes))) {
            return response()->json(['error' => 'Painel exclusivo para responsáveis.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => app(\App\Services\Responsaveis\PainelResponsavelService::class)->gerar((int) ($jwtUser->id ?? 0)),
        ]);
    }
}
