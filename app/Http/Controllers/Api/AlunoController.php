<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\CancelamentoMatriculaFinanceiraService;
use App\Services\Sistema\LogSistemaService;
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
    private const FOTO_BASE_URL = 'https://portalmasterschool.com.br/cepelc/api/';

    public function __construct(
        private LogSistemaService $log,
        private CancelamentoMatriculaFinanceiraService $cancelamentoFinanceiro,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas administradores podem listar todos os alunos.'], 403);
        }

        $rows = DB::select("
            SELECT
                a.id AS aluno_id, u.id AS usuario_id, u.nome_completo AS nome_aluno, u.email, u.ativo,
                u.data_cadastro, a.matricula, a.data_nascimento, a.endereco, a.status_matricula, a.foto_path,
                t.nome_turma,
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
                a.id, u.id, u.nome_completo, u.email, u.ativo, u.data_cadastro,
                a.matricula, a.data_nascimento, a.endereco, a.status_matricula, a.foto_path, t.nome_turma,
                mc.motivo_encerramento, mc.motivo_detalhe, mc.data_fim, uc.nome_completo
            ORDER BY u.nome_completo ASC
        ");

        $alunos = array_map(function ($row) {
            $arr = (array) $row;
            $arr['ativo'] = (bool) $row->ativo;
            $arr['foto_url'] = !empty($row->foto_path) ? self::FOTO_BASE_URL . $row->foto_path : null;
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
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas administradores podem cadastrar alunos.'], 403);
        }

        $nomeCompleto = $request->input('nome_completo');
        $email = $request->input('email');
        $senhaBruta = $request->input('senha');
        $turmaId = $request->input('turma_id');
        $matricula = $request->input('matricula');
        $dataNascimento = $request->input('data_nascimento');
        $endereco = $request->input('endereco');
        $responsaveisIds = $request->input('responsaveis_ids', []);

        if (!$nomeCompleto || !$email || !$senhaBruta || !$turmaId || !$matricula) {
            return response()->json(['error' => 'Os campos obrigatórios (nome_completo, email, senha, turma_id, matricula) devem ser fornecidos.'], 400);
        }

        try {
            [$alunoId, $usuarioId] = DB::transaction(function () use ($nomeCompleto, $email, $senhaBruta, $turmaId, $matricula, $dataNascimento, $endereco, $responsaveisIds) {
                $usuarioId = DB::table('usuarios')->insertGetId([
                    'nome_completo' => $nomeCompleto,
                    'email'         => $email,
                    'senha'         => Hash::make($senhaBruta),
                    'data_cadastro' => now(),
                    'ativo'         => 1,
                ]);

                $alunoId = DB::table('alunos')->insertGetId([
                    'usuario_id'      => $usuarioId,
                    'matricula'       => $matricula,
                    'data_nascimento' => $dataNascimento,
                    'endereco'        => $endereco,
                    'status_matricula' => 'Ativo',
                ]);

                DB::table('turma_aluno')->insert(['aluno_id' => $alunoId, 'turma_id' => $turmaId]);

                $anoLetivo = DB::table('turmas')->where('id', $turmaId)->value('ano_letivo');
                DB::table('matriculas')->insert([
                    'aluno_id'    => $alunoId,
                    'turma_id'    => $turmaId,
                    'ano_letivo'  => $anoLetivo,
                    'data_inicio' => now()->toDateString(),
                    'status'      => 'ativa',
                    'origem'      => 'sistema',
                ]);

                // ID 4 = Aluno (ver tabela funcoes).
                DB::table('usuario_funcao')->insert(['usuario_id' => $usuarioId, 'funcao_id' => 4]);

                foreach ($responsaveisIds as $responsavelId) {
                    if (!is_numeric($responsavelId)) {
                        continue;
                    }
                    // parentesco é NOT NULL sem default no schema local (modo estrito) — o
                    // original PHP não informava esse campo, contando com a leniência do
                    // MySQL de produção. Explicitando '' pra funcionar em qualquer modo.
                    DB::table('aluno_responsavel')->insert(['aluno_id' => $alunoId, 'responsavel_id' => $responsavelId, 'parentesco' => '']);
                }

                return [$alunoId, $usuarioId];
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha no cadastro transacional do aluno.', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success'    => 'Aluno e seus vínculos cadastrados com sucesso.',
            'aluno_id'   => $alunoId,
            'usuario_id' => $usuarioId,
            'matricula'  => $matricula,
        ], 201);
    }

    public function inativar(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas administradores podem inativar um aluno.'], 403);
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

        $isAdminOuProfessor = !empty(array_intersect(['Administrador', 'Diretoria', 'Professor', 'Docente'], $userRoles));
        $isResponsavel = in_array('Responsável', $userRoles, true) || in_array('Responsavel', $userRoles, true);

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
        if (empty(array_intersect(['Administrador', 'Professor'], $funcoes))) {
            return response()->json(['error' => 'Acesso negado. Apenas professores e administradores.'], 403);
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
     * Salva no disco local do Laravel — atenção: a foto_url exibida no front
     * sempre aponta pro domínio de produção (self::FOTO_BASE_URL), então uma
     * foto enviada aqui só fica visível de verdade quando este backend estiver
     * servindo esse mesmo domínio. Testar upload localmente não vai mostrar a
     * imagem — isso é esperado nesta fase da migração, não é bug.
     */
    public function setFoto(Request $request): JsonResponse
    {
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

        $updated = DB::table('alunos')->where('id', $alunoId)->update(['foto_path' => $caminhoBanco]);
        if (!$updated) {
            Storage::disk('public')->delete('fotos_alunos/' . $novoNome);
            return response()->json(['error' => 'Erro ao atualizar o caminho no banco de dados.'], 500);
        }

        return response()->json(['success' => 'Foto atualizada com sucesso!', 'path' => $caminhoBanco]);
    }

    public function deleteFoto(Request $request): JsonResponse
    {
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
}
