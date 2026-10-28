<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlunoResponsavelVinculoService;
use App\Services\Financeiro\CancelamentoMatriculaFinanceiraService;
use App\Services\Sistema\LogSistemaService;
use App\Support\Papeis;
use App\Support\Permissoes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Equivalente a api/alunos/*.php.
 */
class AlunoController extends Controller
{
    private const MOTIVOS_VALIDOS = ['transferencia_externa', 'evasao', 'conclusao', 'solicitacao_familia', 'outro'];

    public function __construct(
        private LogSistemaService $log,
        private CancelamentoMatriculaFinanceiraService $cancelamentoFinanceiro,
        private AlunoResponsavelVinculoService $vinculos,
        private \App\Services\Cadastro\CadastroValidacaoService $validacao,
        private \App\Services\Matriculas\NumeroMatriculaService $numeros,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!Permissoes::pode($funcoes, Permissoes::PESSOAS_VER)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não consulta o cadastro de alunos.'], 403);
        }

        $rows = DB::select("
            SELECT
                a.id AS aluno_id, u.id AS usuario_id, u.nome_completo AS nome_aluno, u.email, u.ativo, u.acesso_portal,
                u.data_cadastro, u.telefone, a.matricula, a.cpf, a.data_nascimento, a.sexo, a.endereco, a.numero, a.complemento,
                a.bairro, a.cidade, a.uf, a.cep, a.observacoes_saude, a.status_matricula, a.foto_path,
                t.id AS turma_id, t.nome_turma,
                mc.motivo_encerramento, mc.motivo_detalhe AS motivo_encerramento_detalhe, mc.data_fim AS data_encerramento,
                uc.nome_completo AS encerrado_por_nome,
                GROUP_CONCAT(DISTINCT CONCAT(ur.nome_completo, ' (', ur.email, ')') SEPARATOR ' ; ') AS responsaveis_vinculados
            FROM alunos a
            JOIN usuarios u ON a.usuario_id = u.id
            LEFT JOIN turma_aluno ta ON a.id = ta.aluno_id
            LEFT JOIN turmas t ON ta.turma_id = t.id
            LEFT JOIN aluno_responsavel ar ON a.id = ar.aluno_id
            LEFT JOIN responsaveis r ON ar.responsavel_id = r.id
            LEFT JOIN usuarios ur ON r.usuario_id = ur.id
            LEFT JOIN (
                SELECT m1.* FROM matriculas m1
                INNER JOIN (SELECT aluno_id, MAX(id) AS max_id FROM matriculas WHERE status = 'cancelada' GROUP BY aluno_id) m2
                    ON m1.id = m2.max_id
            ) mc ON mc.aluno_id = a.id
            LEFT JOIN usuarios uc ON mc.encerrado_por = uc.id
            GROUP BY
                a.id, u.id, u.nome_completo, u.email, u.ativo, u.acesso_portal, u.data_cadastro, u.telefone,
                a.matricula, a.cpf, a.data_nascimento, a.sexo, a.endereco, a.numero, a.complemento, a.bairro, a.cidade, a.uf, a.cep,
                a.observacoes_saude, a.status_matricula, a.foto_path, t.id, t.nome_turma,
                mc.motivo_encerramento, mc.motivo_detalhe, mc.data_fim, uc.nome_completo
            ORDER BY u.nome_completo ASC
        ");

        $alunos = array_map(function ($row) {
            $arr = (array) $row;
            $arr['ativo'] = (bool) $row->ativo;
            $arr['acesso_portal'] = (bool) $row->acesso_portal;
            $arr['foto_url'] = \App\Services\Alunos\FotoAlunoService::url($row->foto_path);
            $arr['responsaveis_array'] = $row->responsaveis_vinculados ? explode(' ; ', $row->responsaveis_vinculados) : [];
            unset($arr['responsaveis_vinculados']);

            return $arr;
        }, $rows);

        return response()->json(['success' => true, 'count' => count($alunos), 'data' => $alunos]);
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!Permissoes::pode($funcoes, Permissoes::PESSOAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não cadastra alunos.'], 403);
        }

        $v = $this->validacao;
        $nomeCompleto = $v->texto($request->input('nome_completo'));
        $email = $v->texto($request->input('email'));
        $senhaBruta = (string) $request->input('senha');
        // Criança não precisa de login: o acesso ao portal é opcional (e-mail
        // e senha só são exigidos quando ele é criado).
        $acessoPortal = $request->boolean('acesso_portal');
        $turmaId = (int) $request->input('turma_id');
        $dataNascimento = $v->texto($request->input('data_nascimento'));
        $responsaveis = $this->responsaveisDoRequest($request);

        $turma = $turmaId ? DB::table('turmas')->where('id', $turmaId)->first(['id', 'ano_letivo', 'status']) : null;
        // Sem número digitado, a sequência do ano é reservada dentro da transação.
        $matricula = $v->texto($request->input('matricula'));

        $erro = (!$nomeCompleto ? 'Informe o nome completo.' : null)
            ?? (!$dataNascimento ? 'Informe a data de nascimento.' : null)
            ?? (!$turma ? 'Escolha a turma.' : null)
            ?? ($turma->status !== 'Ativa' ? 'Essa turma está encerrada — escolha uma turma ativa.' : null)
            ?? $v->validarEmail($email, null, $acessoPortal)
            ?? ($acessoPortal ? $v->validarSenha($senhaBruta) : null)
            ?? $v->validarCpf('alunos', $request->input('cpf'), false)
            ?? $v->validarTelefone($request->input('telefone'), false)
            ?? $v->validarEndereco($request->all(), false)
            ?? $this->validarSexo($request->input('sexo'))
            ?? (!$responsaveis ? 'Vincule pelo menos um responsável.' : null)
            ?? ($matricula && $this->numeros->emUso($matricula) ? 'Este número de matrícula já está em uso.' : null);
        if ($erro) {
            return response()->json(['error' => $erro], 422);
        }

        try {
            [$alunoId, $usuarioId] = DB::transaction(function () use ($request, $v, $nomeCompleto, $email, $senhaBruta, $acessoPortal, $turma, &$matricula, $dataNascimento, $responsaveis) {
                $matricula ??= $this->numeros->proximo((int) $turma->ano_letivo);
                $usuarioId = DB::table('usuarios')->insertGetId([
                    'nome_completo' => $nomeCompleto,
                    'email'         => $email,
                    'senha'         => $acessoPortal ? Hash::make($senhaBruta) : null,
                    'telefone'      => $v->telefone($request->input('telefone')),
                    'data_cadastro' => now(),
                    'ativo'         => 1,
                    'acesso_portal' => $acessoPortal ? 1 : 0,
                ]);

                $alunoId = DB::table('alunos')->insertGetId([
                    'usuario_id'        => $usuarioId,
                    'matricula'         => $matricula,
                    'cpf'               => $v->formatarCpf($request->input('cpf')),
                    'data_nascimento'   => $dataNascimento,
                    'sexo'              => $v->texto($request->input('sexo')),
                    'observacoes_saude' => $v->texto($request->input('observacoes_saude')),
                    'status_matricula'  => 'Ativo',
                ] + $v->endereco($request->all()));

                DB::table('turma_aluno')->insert(['aluno_id' => $alunoId, 'turma_id' => $turma->id]);
                DB::table('matriculas')->insert($this->numeros->linha([
                    'aluno_id'    => $alunoId,
                    'turma_id'    => $turma->id,
                    'ano_letivo'  => $turma->ano_letivo,
                    'data_inicio' => now()->toDateString(),
                    'status'      => 'ativa',
                    'origem'      => 'sistema',
                ], $matricula));

                DB::table('usuario_funcao')->insert([
                    'usuario_id' => $usuarioId,
                    'funcao_id'  => DB::table('funcoes')->where('nome_funcao', Papeis::ALUNO)->value('id'),
                ]);

                $this->sincronizarResponsaveis($alunoId, $responsaveis);

                return [$alunoId, $usuarioId];
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => $v->mensagemDuplicidade($e) ?? 'Falha ao cadastrar o aluno.', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success'    => 'Aluno e seus vínculos cadastrados com sucesso.',
            'aluno_id'   => $alunoId,
            'usuario_id' => $usuarioId,
            'matricula'  => $matricula,
        ], 201);
    }

    /** Sugestão de matrícula para o formulário: ano + sequência (ex.: 2027001). */
    public function proximaMatriculaSugerida(Request $request): JsonResponse
    {
        $ano = (int) $request->query('ano', now()->year);
        return response()->json(['success' => true, 'matricula' => $this->numeros->sugerir($ano)]);
    }

    private function validarSexo($sexo): ?string
    {
        return ($sexo === null || $sexo === '' || in_array($sexo, ['F', 'M', 'O'], true)) ? null : 'Sexo inválido.';
    }

    /**
     * Responsáveis do formulário: [{id, parentesco, principal}]. Aceita também o
     * formato antigo responsaveis_ids. Garante exatamente um principal
     * (o responsável financeiro — é quem recebe o boleto).
     *
     * @return array<int, array{id:int, parentesco:string, principal:bool}>
     */
    private function responsaveisDoRequest(Request $request): array
    {
        $lista = $request->input('responsaveis');
        if (!is_array($lista)) {
            $lista = array_map(fn ($id) => ['id' => $id], (array) $request->input('responsaveis_ids', []));
        }
        $out = [];
        foreach ($lista as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id && !isset($out[$id]) && DB::table('responsaveis')->where('id', $id)->exists()) {
                $out[$id] = ['id' => $id, 'parentesco' => mb_substr(trim((string) ($r['parentesco'] ?? '')), 0, 50), 'principal' => !empty($r['principal'])];
            }
        }
        $out = array_values($out);
        if ($out && !collect($out)->contains('principal', true)) {
            $out[0]['principal'] = true;
        }
        $achou = false;
        foreach ($out as &$r) {
            $r['principal'] = $r['principal'] && !$achou;
            $achou = $achou || $r['principal'];
        }
        return $out;
    }

    /** Deixa aluno_responsavel exatamente como a lista (inclui, atualiza, remove). */
    private function sincronizarResponsaveis(int $alunoId, array $responsaveis): void
    {
        $ids = array_column($responsaveis, 'id');
        DB::table('aluno_responsavel')->where('aluno_id', $alunoId)->whereNotIn('responsavel_id', $ids ?: [0])->delete();
        foreach ($responsaveis as $r) {
            DB::table('aluno_responsavel')->updateOrInsert(
                ['aluno_id' => $alunoId, 'responsavel_id' => $r['id']],
                ['parentesco' => $r['parentesco'], 'principal' => $r['principal'] ? 1 : 0]
            );
        }
    }

    /** Dados do aluno para o formulário de edição (inclui responsáveis com parentesco). */
    public function show(Request $request, int $id): JsonResponse
    {
        $funcoes = (array) ($request->attributes->get('max_user')->funcoes ?? $request->attributes->get('max_user')->roles ?? []);
        if (!Permissoes::pode($funcoes, Permissoes::PESSOAS_VER)) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }
        $aluno = DB::table('alunos as a')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->leftJoin('turma_aluno as ta', 'ta.aluno_id', '=', 'a.id')
            ->leftJoin('turmas as t', 't.id', '=', 'ta.turma_id')
            ->where('a.id', $id)
            ->orderByDesc('t.ano_letivo')
            ->first(['a.id as aluno_id', 'u.id as usuario_id', 'u.nome_completo', 'u.email', 'u.telefone', 'u.ativo', 'u.acesso_portal', 'a.matricula', 'a.cpf',
                'a.data_nascimento', 'a.sexo', 'a.cep', 'a.endereco', 'a.numero', 'a.complemento', 'a.bairro', 'a.cidade', 'a.uf',
                'a.observacoes_saude', 'a.status_matricula', 't.id as turma_id', 't.nome_turma', 't.ano_letivo']);
        if (!$aluno) {
            return response()->json(['error' => 'Aluno não encontrado.'], 404);
        }
        $aluno->acesso_portal = (bool) $aluno->acesso_portal;
        $aluno->responsaveis = DB::table('aluno_responsavel as ar')
            ->join('responsaveis as r', 'r.id', '=', 'ar.responsavel_id')
            ->join('usuarios as u', 'u.id', '=', 'r.usuario_id')
            ->where('ar.aluno_id', $id)
            ->orderByDesc('ar.principal')->orderBy('u.nome_completo')
            ->get(['r.id', 'u.nome_completo as nome', 'u.email', 'r.cpf', 'r.telefone', 'ar.parentesco', 'ar.principal',
                'r.cep', 'r.endereco', 'r.numero', 'r.complemento', 'r.bairro', 'r.cidade', 'r.uf'])
            ->map(function ($r) {
                $r->principal = (bool) $r->principal;
                return $r;
            });

        return response()->json(['success' => true, 'data' => $aluno]);
    }

    public function inativar(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!Permissoes::pode($funcoes, Permissoes::PESSOAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não pode inativar alunos.'], 403);
        }

        $alunoId = $request->input('aluno_id');
        $motivo = $request->input('motivo');
        $motivoDetalhe = $request->input('motivo_detalhe');
        $dataEncerramento = $request->input('data_encerramento', now()->toDateString());

        if (!$alunoId || !is_numeric($alunoId)) {
            return response()->json(['error' => 'aluno_id é obrigatório e deve ser numérico.'], 400);
        }
        if (!$motivo || !in_array($motivo, self::MOTIVOS_VALIDOS, true)) {
            return response()->json(['error' => 'motivo é obrigatório. Valores aceitos: ' . implode(', ', self::MOTIVOS_VALIDOS)], 400);
        }
        $alunoId = (int) $alunoId;

        $usuarioIdAluno = DB::table('alunos')->where('id', $alunoId)->value('usuario_id');
        if (!$usuarioIdAluno) {
            return response()->json(['error' => 'Aluno não encontrado.'], 404);
        }

        try {
            $resultado = DB::transaction(function () use ($alunoId, $usuarioIdAluno, $motivo, $motivoDetalhe, $dataEncerramento, $usuarioIdLogado) {
                DB::table('usuarios')->where('id', $usuarioIdAluno)->update(['ativo' => 0]);

                $matriculasEncerradas = DB::table('matriculas')
                    ->where('aluno_id', $alunoId)
                    ->where('status', 'ativa')
                    ->update([
                        'status'              => 'cancelada',
                        'data_fim'            => $dataEncerramento,
                        'motivo_encerramento' => $motivo,
                        'motivo_detalhe'      => $motivoDetalhe,
                        'encerrado_por'       => $usuarioIdLogado,
                        'encerrado_em'        => now(),
                    ]);

                DB::table('alunos')->where('id', $alunoId)->update(['status_matricula' => 'Inativo']);

                // Sem isso o aluno inativo continuava "na turma": não aparecia na
                // virada de ano (lista só ativos) e impedia encerrar a turma.
                DB::table('turma_aluno')->where('aluno_id', $alunoId)->delete();

                $motivoFinanceiroMap = ['transferencia_externa' => 'aluno_transferido', 'evasao' => 'aluno_evadido'];
                $motivoFinanceiro = $motivoFinanceiroMap[$motivo] ?? 'outro';

                $mfAtivas = DB::table('matriculas_financeiras')
                    ->where('aluno_id', $alunoId)
                    ->where('status', 'ativa')
                    ->pluck('id');

                $matriculasFinanceirasCanceladas = 0;
                foreach ($mfAtivas as $mfId) {
                    $this->cancelamentoFinanceiro->cancelar(
                        (int) $mfId,
                        $motivoFinanceiro,
                        "Cancelamento automático — aluno inativado (motivo: $motivo).",
                        false,
                        $usuarioIdLogado
                    );
                    $matriculasFinanceirasCanceladas++;
                }

                return [$matriculasEncerradas, $matriculasFinanceirasCanceladas];
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Erro ao inativar aluno.', 'message' => $e->getMessage()], 400);
        }

        [$matriculasEncerradas, $matriculasFinanceirasCanceladas] = $resultado;

        $this->log->registrar(
            $request, $usuarioIdLogado, $jwtUser->nome ?? null, 'INATIVACAO_ALUNO', '/api/alunos/inativar', 'POST',
            "Aluno ID $alunoId inativado. Motivo: $motivo.",
            200,
            [
                'aluno_id' => $alunoId, 'motivo' => $motivo, 'motivo_detalhe' => $motivoDetalhe,
                'data_encerramento' => $dataEncerramento, 'matriculas_encerradas' => $matriculasEncerradas,
                'matriculas_financeiras_canceladas' => $matriculasFinanceirasCanceladas,
            ]
        );

        return response()->json([
            'success'                           => "Aluno ID $alunoId inativado com sucesso.",
            'aluno_id'                           => $alunoId,
            'matriculas_encerradas'              => $matriculasEncerradas,
            'matriculas_financeiras_canceladas'  => $matriculasFinanceirasCanceladas,
        ]);
    }

    public function boletim(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $userIdToken = (int) ($jwtUser->id ?? 0);
        $userRoles = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        $alunoIdParam = $request->query('aluno_id');
        $unidadeId = $request->query('unidade_id');
        $anoLetivo = $request->query('ano_letivo');

        if (!$alunoIdParam || !$unidadeId || !is_numeric($alunoIdParam) || !is_numeric($unidadeId)) {
            return response()->json(['error' => 'ID do aluno (aluno_id) e ID da unidade letiva/bimestre (unidade_id) são obrigatórios e devem ser numéricos.'], 400);
        }
        $alunoId = (int) $alunoIdParam;
        $unidadeId = (int) $unidadeId;
        $anoLetivo = ($anoLetivo && is_numeric($anoLetivo)) ? (int) $anoLetivo : null;

        $turmaIdResolvido = null;
        if ($anoLetivo) {
            $turmaIdResolvido = DB::selectOne("
                SELECT turma_id FROM matriculas
                WHERE aluno_id = ? AND ano_letivo = ?
                ORDER BY (status = 'ativa') DESC, id DESC
                LIMIT 1
            ", [$alunoId, $anoLetivo])->turma_id ?? null;

            if (!$turmaIdResolvido) {
                return response()->json([
                    'aluno_id' => $alunoId, 'nome_aluno' => '', 'unidade_id' => $unidadeId, 'disciplinas' => [],
                ]);
            }
        }

        $isAdminOuProfessor = Papeis::ehProfessor($userRoles) || Permissoes::pode($userRoles, Permissoes::PEDAGOGICO_VER);
        $isResponsavel = Papeis::ehResponsavel($userRoles);

        if (!$isAdminOuProfessor) {
            if ($isResponsavel) {
                $temDependente = DB::table('responsaveis as r')
                    ->join('aluno_responsavel as ar', 'r.id', '=', 'ar.responsavel_id')
                    ->where('r.usuario_id', $userIdToken)
                    ->where('ar.aluno_id', $alunoId)
                    ->exists();

                if (!$temDependente) {
                    return response()->json(['error' => 'Acesso negado. Este aluno não é seu dependente.'], 403);
                }
            } else {
                $usuarioIdAluno = DB::table('alunos')->where('id', $alunoId)->value('usuario_id');
                if (!$usuarioIdAluno) {
                    return response()->json(['error' => 'Aluno não encontrado.'], 404);
                }
                if ($userIdToken !== (int) $usuarioIdAluno) {
                    return response()->json(['error' => 'Acesso negado. Você só pode visualizar seu próprio boletim.'], 403);
                }
            }
        }

        if ($turmaIdResolvido) {
            $rows = DB::select('
                SELECT
                    d.id AS disciplina_id, d.nome_disciplina, av.id AS avaliacao_id, ta.nome_tipo AS tipo_avaliacao,
                    ta.peso AS peso_avaliacao, av.valor_maximo, n.id AS nota_registro_id,
                    IFNULL(n.nota_valor, 0.0) AS nota_valor, u.nome_completo AS nome_aluno
                FROM alunos al
                JOIN usuarios u ON al.usuario_id = u.id AND al.id = ?
                JOIN turma_professor_disciplina tpd ON tpd.turma_id = ?
                JOIN disciplinas d ON tpd.disciplina_id = d.id
                JOIN avaliacoes av ON av.disciplina_id = d.id AND av.unidade_letiva_id = ? AND av.turma_id = ?
                JOIN tipos_avaliacao ta ON av.tipo_avaliacao_id = ta.id
                LEFT JOIN notas n ON n.aluno_id = al.id AND n.avaliacao_id = av.id
                ORDER BY d.nome_disciplina, ta.peso DESC, av.id
            ', [$alunoId, $turmaIdResolvido, $unidadeId, $turmaIdResolvido]);
        } else {
            $rows = DB::select('
                SELECT
                    d.id AS disciplina_id, d.nome_disciplina, av.id AS avaliacao_id, ta.nome_tipo AS tipo_avaliacao,
                    ta.peso AS peso_avaliacao, av.valor_maximo, n.id AS nota_registro_id,
                    IFNULL(n.nota_valor, 0.0) AS nota_valor, u.nome_completo AS nome_aluno
                FROM alunos al
                JOIN usuarios u ON al.usuario_id = u.id AND al.id = ?
                JOIN turma_aluno ta_aluno ON al.id = ta_aluno.aluno_id
                JOIN turma_professor_disciplina tpd ON ta_aluno.turma_id = tpd.turma_id
                JOIN disciplinas d ON tpd.disciplina_id = d.id
                JOIN avaliacoes av ON av.disciplina_id = d.id AND av.unidade_letiva_id = ? AND av.turma_id = ta_aluno.turma_id
                JOIN tipos_avaliacao ta ON av.tipo_avaliacao_id = ta.id
                LEFT JOIN notas n ON n.aluno_id = al.id AND n.avaliacao_id = av.id
                ORDER BY d.nome_disciplina, ta.peso DESC, av.id
            ', [$alunoId, $unidadeId]);
        }

        if (!$rows) {
            return response()->json(['aluno_id' => $alunoId, 'nome_aluno' => '', 'unidade_id' => $unidadeId, 'disciplinas' => []]);
        }

        $boletim = ['aluno_id' => $alunoId, 'nome_aluno' => '', 'unidade_id' => $unidadeId, 'disciplinas' => []];
        $disciplinasMap = [];

        foreach ($rows as $row) {
            $discId = $row->disciplina_id;
            if ($boletim['nome_aluno'] === '') {
                $boletim['nome_aluno'] = $row->nome_aluno;
            }
            $disciplinasMap[$discId] ??= [
                'disciplina_id' => $discId, 'nome_disciplina' => $row->nome_disciplina, 'avaliacoes' => [],
            ];

            $notaValor = (float) $row->nota_valor;
            $valorMaximo = (float) $row->valor_maximo;
            $temNota = $row->nota_registro_id !== null;

            $disciplinasMap[$discId]['avaliacoes'][] = [
                'avaliacao_id'      => (int) $row->avaliacao_id,
                'tipo_avaliacao'    => $row->tipo_avaliacao,
                'peso'              => (int) $row->peso_avaliacao,
                'valor_maximo'      => $valorMaximo,
                'nota_registro_id'  => $temNota ? (int) $row->nota_registro_id : null,
                'nota_valor'        => $temNota ? $notaValor : null,
            ];
        }

        $boletim['disciplinas'] = array_values($disciplinasMap);

        foreach ($boletim['disciplinas'] as $key => $disciplina) {
            $somaPonderada = 0.0;
            $somaPesos = 0.0;
            foreach ($disciplina['avaliacoes'] as $av) {
                if ($av['nota_registro_id'] !== null && $av['valor_maximo'] > 0) {
                    $somaPonderada += ($av['nota_valor'] / $av['valor_maximo']) * $av['peso'];
                    $somaPesos += $av['peso'];
                }
            }
            $boletim['disciplinas'][$key]['media_bimestral'] = $somaPesos > 0 ? round($somaPonderada / 10, 2) : null;
        }

        return response()->json($boletim);
    }

    public function boletimTurma(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!Papeis::ehProfessor($funcoes) && !Permissoes::pode($funcoes, Permissoes::PEDAGOGICO_VER)) {
            return response()->json(['error' => 'Acesso negado. Apenas professores e quem acompanha as turmas.'], 403);
        }

        $turmaIdParam = $request->query('turma_id');
        $anoLetivoParam = $request->query('ano_letivo');

        if (!$turmaIdParam || !is_numeric($turmaIdParam) || ($anoLetivoParam !== null && !is_numeric($anoLetivoParam))) {
            return response()->json(['error' => 'ID da turma (turma_id) é obrigatório e Ano Letivo deve ser numérico quando fornecido.'], 400);
        }
        $turmaId = (int) $turmaIdParam;

        if ($anoLetivoParam !== null) {
            $anoLetivoFiltro = (int) $anoLetivoParam;
        } else {
            $anoLetivoFiltro = DB::table('turmas')->where('id', $turmaId)->value('ano_letivo');
            if (!$anoLetivoFiltro) {
                return response()->json(['error' => 'Turma não encontrada.'], 404);
            }
            $anoLetivoFiltro = (int) $anoLetivoFiltro;
        }

        $rows = DB::select('
            SELECT ul.id AS unidade_id, ul.nome_unidade, IFNULL(AVG(dm.media_disciplina), 0.0) AS media_geral_bimestre
            FROM unidades_letivas ul
            LEFT JOIN (
                SELECT av.unidade_letiva_id, av.disciplina_id, AVG(n.nota_valor) AS media_disciplina
                FROM avaliacoes av
                JOIN notas n ON n.avaliacao_id = av.id
                JOIN (SELECT DISTINCT aluno_id, turma_id FROM matriculas) ta_aluno
                    ON n.aluno_id = ta_aluno.aluno_id AND ta_aluno.turma_id = ?
                JOIN turma_professor_disciplina tpd ON tpd.turma_id = ta_aluno.turma_id AND tpd.disciplina_id = av.disciplina_id
                GROUP BY av.unidade_letiva_id, av.disciplina_id
            ) AS dm ON ul.id = dm.unidade_letiva_id
            WHERE ul.ano_letivo = ?
            GROUP BY ul.id, ul.nome_unidade
            ORDER BY ul.id ASC
        ', [$turmaId, $anoLetivoFiltro]);

        if (!$rows) {
            return response()->json(['error' => "Nenhuma unidade letiva encontrada para o ano de $anoLetivoFiltro."], 404);
        }

        $labels = array_map(fn ($r) => $r->nome_unidade, $rows);
        $dataMedias = array_map(fn ($r) => round((float) $r->media_geral_bimestre, 2), $rows);

        return response()->json([
            'labels'   => $labels,
            'datasets' => [[
                'label'       => "Média Geral da Turma ID: $turmaId",
                'data'        => $dataMedias,
                'fill'        => false,
                'borderColor' => '#42A5F5',
                'tension'     => 0.4,
            ]],
        ]);
    }

    /**
     * Salva no disco "public" do Laravel (servido em /storage) e devolve a
     * foto_url pronta — ver FotoAlunoService. A foto anterior, se era deste
     * backend, é apagada para não acumular arquivos.
     */
    public function setFoto(Request $request): JsonResponse
    {
        // Fix: não havia checagem nenhuma — qualquer usuário logado trocava a
        // foto de qualquer aluno.
        if (!Permissoes::pode($this->funcoes($request), Permissoes::PESSOAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não edita o cadastro de alunos.'], 403);
        }
        $alunoId = $request->input('aluno_id');
        $file = $request->file('foto');

        if (!$alunoId || !$file) {
            return response()->json(['error' => 'ID do aluno e arquivo de imagem são obrigatórios.'], 400);
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $allowedExtensions, true)) {
            return response()->json(['error' => 'Formato de imagem inválido. Use JPG, PNG ou WEBP.'], 500);
        }

        $novoNome = 'aluno_' . $alunoId . '_' . time() . '.' . $extension;

        try {
            Storage::disk('public')->putFileAs('fotos_alunos', $file, $novoNome);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Não foi possível salvar o arquivo no servidor.'], 500);
        }

        $caminhoBanco = 'fotos_alunos/' . $novoNome;
        $fotoAnterior = DB::table('alunos')->where('id', $alunoId)->value('foto_path');

        $updated = DB::table('alunos')->where('id', $alunoId)->update(['foto_path' => $caminhoBanco]);
        if (!$updated) {
            Storage::disk('public')->delete('fotos_alunos/' . $novoNome);
            return response()->json(['error' => 'Erro ao atualizar o caminho no banco de dados.'], 500);
        }

        if ($fotoAnterior && $fotoAnterior !== $caminhoBanco) {
            Storage::disk('public')->delete($fotoAnterior);
        }

        return response()->json(['success' => 'Foto atualizada com sucesso!', 'path' => $caminhoBanco, 'foto_url' => \App\Services\Alunos\FotoAlunoService::url($caminhoBanco)]);
    }

    public function deleteFoto(Request $request): JsonResponse
    {
        if (!Permissoes::pode($this->funcoes($request), Permissoes::PESSOAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não edita o cadastro de alunos.'], 403);
        }
        $alunoId = $request->input('aluno_id');
        if (!$alunoId) {
            return response()->json(['error' => 'ID do aluno é obrigatório.'], 400);
        }

        $caminhoFoto = DB::table('alunos')->where('id', $alunoId)->value('foto_path');
        if ($caminhoFoto === null && !DB::table('alunos')->where('id', $alunoId)->exists()) {
            return response()->json(['error' => 'Aluno não encontrado.'], 500);
        }

        DB::table('alunos')->where('id', $alunoId)->update(['foto_path' => null]);

        if ($caminhoFoto) {
            Storage::disk('public')->delete($caminhoFoto);
        }

        return response()->json(['success' => 'Foto removida com sucesso.']);
    }

    /**
     * Equivalente a api/admin/update_aluno.php — edição administrativa (nome,
     * email, matrícula, data de nascimento, endereço, status_matricula).
     * Diferente de inativar(): aqui é edição livre de campos, sem as regras de
     * negócio de encerramento de matrícula/financeiro.
     */
    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!Permissoes::pode($funcoes, Permissoes::PESSOAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não edita o cadastro de alunos.'], 403);
        }

        $alunoId = (int) $request->input('aluno_id');
        if (!$alunoId) {
            return response()->json(['error' => 'O campo aluno_id é obrigatório para editar.'], 400);
        }
        $atual = DB::table('alunos')->where('id', $alunoId)->first();
        if (!$atual) {
            return response()->json(['error' => "Aluno ID $alunoId não encontrado no sistema."], 404);
        }
        $usuarioId = (int) $atual->usuario_id;
        $v = $this->validacao;

        // Aceita 'nome_aluno' (payload histórico do front) ou 'nome_completo'.
        $novoNome = $request->input('nome_completo', $request->input('nome_aluno'));
        $camposEndereco = ['cep', 'endereco', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];
        $responsaveis = $request->has('responsaveis') || $request->has('responsaveis_ids') ? $this->responsaveisDoRequest($request) : null;

        $erro = ($novoNome !== null && !$v->texto($novoNome) ? 'Informe o nome completo.' : null)
            ?? ($request->has('email') ? $v->validarEmail($request->input('email'), $usuarioId, $this->temAcessoPortal($usuarioId)) : null)
            ?? ($request->filled('senha') ? $v->validarSenha($request->input('senha')) : null)
            ?? ($request->has('cpf') && $v->formatarCpf($request->input('cpf')) !== $atual->cpf ? $v->validarCpf('alunos', $request->input('cpf'), false, $alunoId) : null)
            ?? ($request->has('telefone') ? $v->validarTelefone($request->input('telefone'), false) : null)
            ?? $v->validarEndereco($request->only($camposEndereco), false)
            ?? $this->validarSexo($request->input('sexo'))
            ?? ($request->has('data_nascimento') && !$v->texto($request->input('data_nascimento')) ? 'Informe a data de nascimento.' : null)
            ?? ($responsaveis !== null && !$responsaveis ? 'O aluno precisa de pelo menos um responsável.' : null)
            ?? ($request->has('matricula') && $this->numeros->emUso((string) $request->input('matricula'), $alunoId)
                ? 'Este número de matrícula já está em uso.' : null);
        if ($erro) {
            return response()->json(['error' => $erro], 422);
        }

        $camposUsuario = [];
        if ($novoNome !== null) {
            $camposUsuario['nome_completo'] = $v->texto($novoNome);
        }
        if ($request->has('email')) {
            $camposUsuario['email'] = $v->texto($request->input('email'));
        }
        if ($request->filled('senha')) {
            $camposUsuario['senha'] = Hash::make($request->input('senha'));
        }
        if ($request->has('telefone')) {
            $camposUsuario['telefone'] = $v->telefone($request->input('telefone'));
        }

        $camposAluno = [];
        foreach (['matricula', 'data_nascimento', 'status_matricula', 'sexo', 'observacoes_saude'] as $campo) {
            if ($request->has($campo)) {
                $camposAluno[$campo] = $v->texto($request->input($campo));
            }
        }
        if ($request->has('cpf')) {
            $camposAluno['cpf'] = $v->formatarCpf($request->input('cpf'));
        }
        $veioEndereco = array_keys($request->only($camposEndereco));
        if ($veioEndereco) {
            $camposAluno += array_intersect_key($v->endereco($request->all()), array_flip($veioEndereco));
        }

        // Troca de turma (mesmo ano, turma ativa): fecha a matrícula atual como
        // "transferida" e abre outra — o histórico de frequência/notas continua certo.
        $novaTurmaId = $request->filled('turma_id') ? (int) $request->input('turma_id') : null;
        $turmaAtual = DB::table('turma_aluno as ta')->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->where('ta.aluno_id', $alunoId)->orderByDesc('t.ano_letivo')->first(['t.id', 't.ano_letivo']);
        $trocarTurma = $novaTurmaId && (!$turmaAtual || $novaTurmaId !== (int) $turmaAtual->id);
        if ($trocarTurma) {
            $nova = DB::table('turmas')->where('id', $novaTurmaId)->first(['id', 'ano_letivo', 'status']);
            if (!$nova || $nova->status !== 'Ativa') {
                return response()->json(['error' => 'A nova turma precisa estar ativa.'], 422);
            }
            if ($turmaAtual && (int) $nova->ano_letivo !== (int) $turmaAtual->ano_letivo) {
                return response()->json(['error' => 'Para mudar o aluno de ano letivo, use a Virada de Ano / Promoção.'], 422);
            }
        }

        try {
            DB::transaction(function () use ($camposUsuario, $camposAluno, $usuarioId, $alunoId, $responsaveis, $trocarTurma, $turmaAtual, $novaTurmaId, $jwtUser) {
                if ($camposUsuario) {
                    DB::table('usuarios')->where('id', $usuarioId)->update($camposUsuario);
                }
                if ($camposAluno) {
                    DB::table('alunos')->where('id', $alunoId)->update($camposAluno);
                }
                // Número corrigido à mão vale para a matrícula do ano em curso.
                if (!empty($camposAluno['matricula']) && $this->numeros->porAno()) {
                    DB::table('matriculas')->where('aluno_id', $alunoId)->where('status', 'ativa')->update(['numero' => $camposAluno['matricula']]);
                }
                if ($responsaveis !== null) {
                    $this->sincronizarResponsaveis($alunoId, $responsaveis);
                }
                if ($trocarTurma) {
                    $ano = DB::table('turmas')->where('id', $novaTurmaId)->value('ano_letivo');
                    if ($turmaAtual) {
                        DB::table('turma_aluno')->where('aluno_id', $alunoId)->where('turma_id', $turmaAtual->id)->delete();
                        DB::table('matriculas')->where('aluno_id', $alunoId)->where('turma_id', $turmaAtual->id)->where('status', 'ativa')
                            ->update(['status' => 'transferida', 'data_fim' => now()->toDateString(), 'encerrado_por' => $jwtUser->id ?? null, 'encerrado_em' => now()]);
                    }
                    DB::table('turma_aluno')->insert(['aluno_id' => $alunoId, 'turma_id' => $novaTurmaId]);
                    // Mesmo ano letivo: o número de matrícula continua o mesmo.
                    DB::table('matriculas')->insert($this->numeros->linha([
                        'aluno_id' => $alunoId, 'turma_id' => $novaTurmaId, 'ano_letivo' => $ano,
                        'data_inicio' => now()->toDateString(), 'status' => 'ativa', 'origem' => 'sistema',
                    ], DB::table('alunos')->where('id', $alunoId)->value('matricula')));
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => $v->mensagemDuplicidade($e) ?? 'Erro ao atualizar o aluno.', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => 'Dados do aluno atualizados com sucesso.']);
    }

    /**
     * Libera ou retira o acesso do aluno ao portal. Liberar exige e-mail (o
     * atual ou um novo) e senha; retirar mantém o cadastro e só bloqueia o
     * login. Body: { acesso: bool, email?: string, senha?: string }
     */
    public function acessoPortal(Request $request, int $id): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        if (!Permissoes::pode($this->funcoes($request), Permissoes::PESSOAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não edita o cadastro de alunos.'], 403);
        }
        $aluno = DB::table('alunos as a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('a.id', $id)->first(['u.id as usuario_id', 'u.nome_completo', 'u.email', 'u.senha']);
        if (!$aluno) {
            return response()->json(['error' => 'Aluno não encontrado.'], 404);
        }

        $v = $this->validacao;
        $liberar = $request->boolean('acesso');
        $campos = ['acesso_portal' => $liberar ? 1 : 0];

        if ($liberar) {
            $email = $v->texto($request->input('email')) ?? $aluno->email;
            $senha = (string) $request->input('senha');
            $erro = $v->validarEmail($email, (int) $aluno->usuario_id)
                ?? (!$aluno->senha || $senha !== '' ? $v->validarSenha($senha) : null);
            if ($erro) {
                return response()->json(['error' => $erro], 422);
            }
            $campos['email'] = $email;
            if ($senha !== '') {
                $campos['senha'] = Hash::make($senha);
            }
        }

        DB::table('usuarios')->where('id', $aluno->usuario_id)->update($campos);

        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, $liberar ? 'ALUNO_ACESSO_LIBERADO' : 'ALUNO_ACESSO_RETIRADO',
            "/api/alunos/$id/acesso", 'POST',
            ($liberar ? 'Acesso ao portal liberado' : 'Acesso ao portal retirado') . " para o aluno {$aluno->nome_completo} (ID $id).", 200,
            ['aluno_id' => $id, 'email' => $campos['email'] ?? $aluno->email]
        );

        return response()->json([
            'success' => $liberar ? 'Acesso ao portal liberado.' : 'Acesso ao portal retirado.',
            'acesso_portal' => $liberar,
            'email' => $campos['email'] ?? $aluno->email,
        ]);
    }

    private function temAcessoPortal(int $usuarioId): bool
    {
        return (bool) DB::table('usuarios')->where('id', $usuarioId)->value('acesso_portal');
    }

    /**
     * Equivalente a api/admin/post_responsavel_aluno.php — vincula vários
     * responsáveis a UM aluno (payload fixa aluno_id, varia responsaveis_ids).
     */
    public function vincularResponsaveis(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!Permissoes::pode($funcoes, Permissoes::PESSOAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não vincula responsáveis a alunos.'], 403);
        }

        $alunoId = $request->input('aluno_id');
        $responsaveisIds = $request->input('responsaveis_ids', []);

        if (!$alunoId || !is_numeric($alunoId)) {
            return response()->json(['error' => 'O ID do aluno (aluno_id) deve ser fornecido.'], 400);
        }
        if (empty($responsaveisIds) || !is_array($responsaveisIds)) {
            return response()->json(['error' => 'A lista de IDs dos responsáveis (responsaveis_ids) deve ser fornecida como um array não vazio.'], 400);
        }

        $responsaveisIdsValidos = array_values(array_filter($responsaveisIds, 'is_numeric'));
        if (empty($responsaveisIdsValidos)) {
            return response()->json(['error' => 'Nenhum ID de responsável válido fornecido para vincular.'], 400);
        }

        [$vinculosCriados, $avisos] = $this->vinculos->criar((int) $alunoId, $responsaveisIdsValidos, porResponsavel: false);

        if ($vinculosCriados > 0) {
            return response()->json([
                'success' => "$vinculosCriados responsáveis vinculados ao Aluno ID $alunoId com sucesso.",
                'aluno_id' => (int) $alunoId,
                'vinculos_criados' => $vinculosCriados,
                'total_solicitado' => count($responsaveisIdsValidos),
                'avisos' => $avisos,
            ], 201);
        }
        if (!empty($avisos)) {
            return response()->json(['success' => 'Nenhum novo vínculo criado.', 'aluno_id' => (int) $alunoId, 'avisos' => $avisos]);
        }

        return response()->json(['error' => 'Nenhuma operação válida executada.'], 400);
    }
}
