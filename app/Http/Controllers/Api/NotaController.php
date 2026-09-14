<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Avaliacoes\ProfessorResolver;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente aos endpoints de notas em api/avaliacoes/*.php.
 */
class NotaController extends Controller
{
    public function __construct(
        private ProfessorResolver $professores,
        private LogSistemaService $log,
    ) {
    }

    public function porAluno(Request $request): JsonResponse
    {
        $alunoId = $request->query('aluno_id');
        $unidadeId = $request->query('unidade_id');

        if (!$alunoId || !is_numeric($alunoId)) {
            return response()->json(['error' => 'ID do aluno é obrigatório.'], 400);
        }

        $sql = '
            SELECT
                n.nota_valor, av.descricao AS nome_avaliacao, av.valor_maximo,
                d.nome_disciplina, ul.nome_unidade,
                ta.nome_tipo AS tipo_avaliacao, ta.peso AS peso_avaliacao
            FROM notas n
            JOIN avaliacoes av ON n.avaliacao_id = av.id
            JOIN disciplinas d ON av.disciplina_id = d.id
            JOIN tipos_avaliacao ta ON av.tipo_avaliacao_id = ta.id
            JOIN unidades_letivas ul ON av.unidade_letiva_id = ul.id
            WHERE n.aluno_id = ?
        ';
        $params = [$alunoId];

        if ($unidadeId !== null && is_numeric($unidadeId)) {
            $sql .= ' AND av.unidade_letiva_id = ?';
            $params[] = $unidadeId;
        }
        $sql .= ' ORDER BY ul.nome_unidade, d.nome_disciplina, ta.peso DESC';

        return response()->json(DB::select($sql, $params));
    }

    public function alunosPorAvaliacao(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $isProfessor = in_array('Professor', $funcoes, true);
        $isAdminOuDiretor = in_array('Administrador', $funcoes, true) || in_array('Diretor', $funcoes, true);

        if (!$isProfessor && !$isAdminOuDiretor) {
            return response()->json(['error' => 'Acesso negado.', 'message' => 'Apenas professores, administradores ou diretores podem visualizar notas para lançamento.'], 403);
        }

        $avaliacaoId = $request->query('avaliacao_id');
        if (!$avaliacaoId || !is_numeric($avaliacaoId)) {
            return response()->json(['error' => 'ID da avaliação é obrigatório e deve ser numérico.'], 400);
        }

        $turmaId = DB::table('avaliacoes')->where('id', $avaliacaoId)->value('turma_id');
        if (!$turmaId) {
            return response()->json(['error' => 'Avaliação não encontrada ou sem turma associada.'], 404);
        }

        $rows = DB::select('
            SELECT
                A.id AS aluno_id, A.matricula, U.nome_completo AS nome_aluno,
                N.nota_valor, N.id AS nota_id, N.segunda_chamada
            FROM turma_aluno TA
            JOIN alunos A ON TA.aluno_id = A.id
            JOIN usuarios U ON A.usuario_id = U.id
            LEFT JOIN notas N ON N.aluno_id = A.id AND N.avaliacao_id = ?
            WHERE TA.turma_id = ? AND U.ativo = 1
            ORDER BY U.nome_completo ASC
        ', [$avaliacaoId, $turmaId]);

        $alunos = array_map(fn ($row) => [
            'aluno_id'        => (int) $row->aluno_id,
            'nome_aluno'      => $row->nome_aluno,
            'matricula'       => $row->matricula,
            'nota_existente'  => $row->nota_valor !== null ? (float) $row->nota_valor : null,
            'nota_id'         => $row->nota_id !== null ? (int) $row->nota_id : null,
            'segunda_chamada' => (bool) $row->segunda_chamada,
        ], $rows);

        return response()->json([
            'turma_id'     => (int) $turmaId,
            'avaliacao_id' => (int) $avaliacaoId,
            'alunos'       => $alunos,
        ]);
    }

    /**
     * Grade de lançamento (aluno x avaliação) de uma turma+disciplina+bimestre —
     * cabeçalho de avaliações + notas de cada aluno.
     */
    public function porTurmaDisciplina(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (empty(array_intersect(['Administrador', 'Professor'], $funcoes))) {
            return response()->json(['error' => 'Acesso negado. Apenas professores e administradores podem acessar esta grade.'], 403);
        }

        $turmaId = $request->query('turma_id');
        $disciplinaId = $request->query('disciplina_id');
        $unidadeId = $request->query('unidade_id');
        $anoLetivo = $request->query('ano_letivo');

        if (!$turmaId || !$disciplinaId || !$unidadeId || !$anoLetivo
            || !is_numeric($turmaId) || !is_numeric($disciplinaId) || !is_numeric($unidadeId) || !is_numeric($anoLetivo)) {
            return response()->json(['error' => 'Turma, Disciplina, Unidade Letiva e Ano Letivo são obrigatórios.'], 400);
        }

        $rows = DB::select("
            SELECT
                al.id AS aluno_id, u.nome_completo AS nome_aluno, d.nome_disciplina AS nome_disciplina,
                GROUP_CONCAT(av_header.id, ':', ta.nome_tipo, ' (', ta.peso, ')', ':', av_header.valor_maximo
                    ORDER BY ta.peso DESC SEPARATOR '|') AS avaliacoes_header,
                GROUP_CONCAT(n.avaliacao_id, ':', IFNULL(n.nota_valor, 0.0), ':', IFNULL(n.id, 0)
                    ORDER BY ta.peso DESC SEPARATOR '|') AS notas_lancadas,
                SUM(n.nota_valor) AS media_bimestral
            FROM alunos al
            JOIN usuarios u ON al.usuario_id = u.id
            JOIN (SELECT DISTINCT aluno_id, turma_id FROM matriculas) ta_aluno
                ON al.id = ta_aluno.aluno_id AND ta_aluno.turma_id = ?
            JOIN avaliacoes av_header
                ON av_header.turma_id = ? AND av_header.disciplina_id = ? AND av_header.unidade_letiva_id = ?
            JOIN unidades_letivas ul ON av_header.unidade_letiva_id = ul.id AND ul.ano_letivo = ?
            JOIN tipos_avaliacao ta ON av_header.tipo_avaliacao_id = ta.id
            JOIN disciplinas d ON av_header.disciplina_id = d.id
            LEFT JOIN notas n ON al.id = n.aluno_id AND n.avaliacao_id = av_header.id
            WHERE ta_aluno.turma_id = ?
            GROUP BY al.id, u.nome_completo, d.nome_disciplina
            ORDER BY u.nome_completo
        ", [$turmaId, $turmaId, $disciplinaId, $unidadeId, $anoLetivo, $turmaId]);

        $avaliacoesHeaderFinal = [];
        $nomeDisciplina = '';
        $gradeDados = [];

        foreach ($rows as $row) {
            if ($nomeDisciplina === '') {
                $nomeDisciplina = $row->nome_disciplina;
            }

            if (!$avaliacoesHeaderFinal && $row->avaliacoes_header) {
                foreach (explode('|', $row->avaliacoes_header) as $avaliacaoStr) {
                    [$id, $nome, $max] = explode(':', $avaliacaoStr);
                    if (!isset($avaliacoesHeaderFinal[$id])) {
                        $avaliacoesHeaderFinal[$id] = ['id' => (int) $id, 'nome' => $nome, 'valor_maximo' => (float) $max];
                    }
                }
                $avaliacoesHeaderFinal = array_values($avaliacoesHeaderFinal);
            }

            $alunoData = [
                'aluno_id'        => $row->aluno_id,
                'nome_aluno'      => $row->nome_aluno,
                'notas'           => [],
                'media_bimestral' => round((float) ($row->media_bimestral ?? 0), 2),
            ];

            if ($row->notas_lancadas) {
                foreach (explode('|', $row->notas_lancadas) as $notaStr) {
                    [$avaliacaoId, $notaValor, $notaRegistroId] = explode(':', $notaStr);
                    $alunoData['notas'][] = [
                        'avaliacao_id'      => (int) $avaliacaoId,
                        'nota_valor'        => round((float) $notaValor, 2),
                        'nota_registro_id'  => (int) $notaRegistroId,
                    ];
                }
            }

            $gradeDados[] = $alunoData;
        }

        return response()->json([
            'disciplina'  => $nomeDisciplina,
            'avaliacoes'  => $avaliacoesHeaderFinal,
            'alunos'      => $gradeDados,
        ]);
    }

    /**
     * Série pra gráfico: média por tipo de avaliação, em todos os bimestres do
     * ano letivo, de uma turma+disciplina.
     */
    public function porTurmaDisciplinaChart(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (empty(array_intersect(['Administrador', 'Professor'], $funcoes))) {
            return response()->json(['error' => 'Acesso negado. Apenas professores e administradores.'], 403);
        }

        $turmaId = $request->query('turma_id');
        $disciplinaId = $request->query('disciplina_id');
        $anoLetivo = $request->query('ano_letivo');

        if (!$turmaId || !$disciplinaId || !$anoLetivo || !is_numeric($turmaId) || !is_numeric($disciplinaId) || !is_numeric($anoLetivo)) {
            return response()->json(['error' => 'Turma, Disciplina e Ano Letivo são obrigatórios.'], 400);
        }

        $rows = DB::select('
            SELECT
                ul.nome_unidade, ul.id AS unidade_id, ta.nome_tipo, ta.id AS tipo_avaliacao_id,
                AVG(n.nota_valor) AS media_por_tipo
            FROM avaliacoes av
            JOIN unidades_letivas ul ON ul.id = av.unidade_letiva_id AND ul.ano_letivo = ?
            JOIN tipos_avaliacao ta ON ta.id = av.tipo_avaliacao_id
            JOIN notas n ON n.avaliacao_id = av.id
            JOIN (SELECT DISTINCT aluno_id, turma_id FROM matriculas) ta_aluno
                ON n.aluno_id = ta_aluno.aluno_id AND ta_aluno.turma_id = ?
            WHERE av.disciplina_id = ?
            GROUP BY ul.id, ul.nome_unidade, ta.id, ta.nome_tipo
            ORDER BY ul.id ASC, ta.peso DESC
        ', [$anoLetivo, $turmaId, $disciplinaId]);

        $unidades = DB::select('SELECT id, nome_unidade FROM unidades_letivas WHERE ano_letivo = ? ORDER BY id ASC', [$anoLetivo]);

        $labelsBimestre = [];
        $unidadesMap = [];
        foreach ($unidades as $index => $unidade) {
            $labelsBimestre[] = $unidade->nome_unidade;
            $unidadesMap[$unidade->id] = $index;
        }

        $dataRaw = [];
        $tiposAvaliacoes = [];
        foreach ($rows as $row) {
            $tipoNome = $row->nome_tipo;
            $tiposAvaliacoes[$tipoNome] ??= $row->tipo_avaliacao_id;
            $dataRaw[$tipoNome][$row->unidade_id] = round((float) $row->media_por_tipo, 2);
        }

        $colors = ['#42A5F5', '#FFA726', '#66BB6A', '#EF5350'];
        $datasets = [];
        $colorIndex = 0;
        foreach ($tiposAvaliacoes as $tipoNome => $tipoId) {
            $datasetData = array_fill(0, count($labelsBimestre), 0.0);
            foreach ($dataRaw[$tipoNome] as $unidadeId => $media) {
                if (isset($unidadesMap[$unidadeId])) {
                    $datasetData[$unidadesMap[$unidadeId]] = $media;
                }
            }
            $datasets[] = [
                'label'       => $tipoNome,
                'data'        => $datasetData,
                'fill'        => false,
                'borderColor' => $colors[$colorIndex % count($colors)],
                'tension'     => 0.4,
            ];
            $colorIndex++;
        }

        return response()->json(['labels' => $labelsBimestre, 'datasets' => $datasets]);
    }

    public function mediaFinalAnual(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (empty(array_intersect(['Administrador', 'Professor', 'Responsável', 'Responsavel'], $funcoes))) {
            return response()->json(['error' => 'Acesso negado. Esta API é restrita.'], 403);
        }

        $turmaId = $request->query('turma_id');
        if (!$turmaId || !is_numeric($turmaId)) {
            return response()->json(['error' => 'ID da turma é obrigatório.'], 400);
        }

        $rows = DB::select('
            SELECT
                al.id AS aluno_id, u.nome_completo AS nome_aluno, d.nome_disciplina,
                ROUND(AVG(bimestral_media.media_bimestral), 2) AS media_final_anual,
                COUNT(bimestral_media.media_bimestral) AS bimestres_lancados,
                CASE WHEN ROUND(AVG(bimestral_media.media_bimestral), 2) >= 60 THEN \'APROVADO\' ELSE \'REPROVADO\' END AS status_final
            FROM (
                SELECT n.aluno_id, av.disciplina_id, av.unidade_letiva_id, SUM(n.nota_valor) AS media_bimestral
                FROM notas n
                JOIN avaliacoes av ON n.avaliacao_id = av.id
                GROUP BY n.aluno_id, av.disciplina_id, av.unidade_letiva_id
            ) AS bimestral_media
            JOIN alunos al ON bimestral_media.aluno_id = al.id
            JOIN usuarios u ON al.usuario_id = u.id
            JOIN disciplinas d ON bimestral_media.disciplina_id = d.id
            JOIN (SELECT DISTINCT aluno_id, turma_id FROM matriculas) ta_aluno
                ON al.id = ta_aluno.aluno_id AND ta_aluno.turma_id = ?
            WHERE ta_aluno.turma_id = ?
            GROUP BY al.id, u.nome_completo, d.id, d.nome_disciplina
            ORDER BY u.nome_completo, d.nome_disciplina
        ', [$turmaId, $turmaId]);

        $mediasFinais = [];
        foreach ($rows as $row) {
            $alunoId = $row->aluno_id;
            $mediasFinais[$alunoId] ??= [
                'aluno_id'    => $alunoId,
                'nome_aluno'  => $row->nome_aluno,
                'disciplinas' => [],
            ];
            $mediasFinais[$alunoId]['disciplinas'][] = [
                'nome_disciplina'    => $row->nome_disciplina,
                'media_final_anual'  => (float) $row->media_final_anual,
                'bimestres_lancados' => (int) $row->bimestres_lancados,
                'status_final'       => $row->status_final,
            ];
        }

        return response()->json(array_values($mediasFinais));
    }

    public function mediasBimestrais(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (empty(array_intersect(['Administrador', 'Professor', 'Responsável', 'Responsavel'], $funcoes))) {
            return response()->json(['error' => 'Acesso negado. Esta API é restrita.'], 403);
        }

        $turmaId = $request->query('turma_id');
        $unidadeId = $request->query('unidade_id');

        if (!$turmaId || !is_numeric($turmaId)) {
            return response()->json(['error' => 'ID da turma é obrigatório.'], 400);
        }
        if (!$unidadeId || !is_numeric($unidadeId)) {
            return response()->json(['error' => 'ID da unidade letiva (bimestre) é obrigatório.'], 400);
        }

        $rows = DB::select('
            SELECT
                al.id AS aluno_id, u.nome_completo AS nome_aluno,
                d.id AS disciplina_id, d.nome_disciplina, ul.nome_unidade,
                SUM(n.nota_valor) AS media_bimestral
            FROM alunos al
            JOIN usuarios u ON al.usuario_id = u.id
            JOIN (SELECT DISTINCT aluno_id, turma_id FROM matriculas) ta_aluno
                ON al.id = ta_aluno.aluno_id AND ta_aluno.turma_id = ?
            JOIN notas n ON al.id = n.aluno_id
            JOIN avaliacoes av ON n.avaliacao_id = av.id
            JOIN disciplinas d ON av.disciplina_id = d.id
            JOIN unidades_letivas ul ON av.unidade_letiva_id = ul.id
            WHERE av.unidade_letiva_id = ?
            GROUP BY al.id, u.nome_completo, d.id, d.nome_disciplina, ul.id, ul.nome_unidade
            ORDER BY u.nome_completo, d.nome_disciplina
        ', [$turmaId, $unidadeId]);

        $medias = [];
        foreach ($rows as $row) {
            $alunoId = $row->aluno_id;
            $medias[$alunoId] ??= [
                'aluno_id'    => $alunoId,
                'nome_aluno'  => $row->nome_aluno,
                'unidade'     => $row->nome_unidade,
                'disciplinas' => [],
            ];
            $medias[$alunoId]['disciplinas'][] = [
                'disciplina_id'    => $row->disciplina_id,
                'nome_disciplina'  => $row->nome_disciplina,
                'media_bimestral'  => round((float) $row->media_bimestral, 2),
            ];
        }

        return response()->json(array_values($medias));
    }

    /**
     * Lançamento em lote (equivalente a post_notas.php). O original dependia
     * de uma UNIQUE KEY (aluno_id, avaliacao_id) que documentava como
     * pré-requisito mas nunca foi criada no banco — já existem pares
     * duplicados nos dados reais. Em vez de assumir a constraint, checa
     * duplicidade explicitamente antes de inserir.
     */
    public function storeLote(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $username = $jwtUser->nome ?? 'desconhecido';
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (empty(array_intersect(['Administrador', 'Professor', 'Diretor'], $funcoes))) {
            return response()->json(['error' => 'Acesso negado. Apenas professores e administradores podem lançar notas.'], 403);
        }

        $avaliacaoId = (int) $request->input('avaliacao_id', 0);
        $alunosNotas = $request->input('alunos_notas', []);

        if (!$avaliacaoId || !is_array($alunosNotas) || count($alunosNotas) === 0) {
            return response()->json(['error' => 'Parâmetros obrigatórios: avaliacao_id e alunos_notas (array).'], 400);
        }

        $valorMaximo = DB::table('avaliacoes')->where('id', $avaliacaoId)->value('valor_maximo');
        if ($valorMaximo === null) {
            return response()->json(['error' => 'Avaliação não encontrada.'], 404);
        }
        $valorMaximo = (float) $valorMaximo;

        foreach ($alunosNotas as $idx => $item) {
            $alunoId = isset($item['aluno_id']) ? (int) $item['aluno_id'] : null;
            $notaValor = isset($item['nota_obtida']) ? (float) $item['nota_obtida'] : null;

            if (!$alunoId || $notaValor === null) {
                return response()->json(['error' => "Item $idx inválido: aluno_id e nota_obtida são obrigatórios."], 400);
            }
            if ($notaValor < 0 || $notaValor > $valorMaximo) {
                return response()->json(['error' => "Nota $notaValor do aluno $alunoId está fora do intervalo permitido (0 a $valorMaximo)."], 400);
            }
        }

        $jaExistentes = DB::table('notas')
            ->where('avaliacao_id', $avaliacaoId)
            ->whereIn('aluno_id', array_column($alunosNotas, 'aluno_id'))
            ->pluck('aluno_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $inseridos = 0;
        $ignorados = 0;

        foreach ($alunosNotas as $item) {
            $alunoId = (int) $item['aluno_id'];
            if (in_array($alunoId, $jaExistentes, true)) {
                $ignorados++;
                continue;
            }

            DB::table('notas')->insert([
                'aluno_id'         => $alunoId,
                'avaliacao_id'     => $avaliacaoId,
                'nota_valor'       => round((float) $item['nota_obtida'], 2),
                'segunda_chamada'  => !empty($item['segunda_chamada']) ? 1 : 0,
            ]);
            $inseridos++;
        }

        $this->log->registrar(
            $request, $usuarioId, $username, 'LANCAMENTO_NOTA', '/api/notas/lote', 'POST',
            "$inseridos nota(s) lançada(s) para avaliação #$avaliacaoId. $ignorados ignorada(s).",
            201,
            ['avaliacao_id' => $avaliacaoId, 'inseridos' => $inseridos, 'ignorados' => $ignorados]
        );

        return response()->json([
            'success'   => true,
            'inseridos' => $inseridos,
            'ignorados' => $ignorados,
            'message'   => "$inseridos nota(s) salva(s). $ignorados já existia(m) e foi(ram) ignorada(s).",
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $username = $jwtUser->nome ?? 'desconhecido';
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (empty(array_intersect(['Administrador', 'Professor', 'Diretor'], $funcoes))) {
            return response()->json(['error' => 'Acesso negado. Apenas professores e administradores podem editar notas.'], 403);
        }

        $notaId = (int) $request->input('nota_id', 0);
        $alunoId = $request->input('aluno_id') ? (int) $request->input('aluno_id') : null;
        $avaliacaoId = $request->input('avaliacao_id') ? (int) $request->input('avaliacao_id') : null;
        $notaValor = $request->input('nota_valor') !== null ? (float) $request->input('nota_valor') : null;
        $turmaId = $request->input('turma_id') ? (int) $request->input('turma_id') : null;
        $segundaChamadaInput = $request->input('segunda_chamada');
        $segundaChamada = $segundaChamadaInput !== null ? (int) (bool) $segundaChamadaInput : null;

        if (!$alunoId || !$avaliacaoId || $notaValor === null || !$turmaId) {
            return response()->json(['error' => 'Parâmetros obrigatórios: aluno_id, avaliacao_id, nota_valor, turma_id.'], 400);
        }
        if ($notaValor < 0) {
            return response()->json(['error' => 'A nota não pode ser negativa.'], 400);
        }

        $turma = DB::table('turmas')->where('id', $turmaId)->first(['status', 'ano_letivo']);
        if (!$turma || strtolower($turma->status) !== 'ativa') {
            return response()->json(['error' => 'Não é possível editar notas de uma turma inativa.'], 403);
        }
        if ((int) $turma->ano_letivo !== (int) now()->year) {
            return response()->json(['error' => 'Só é possível editar notas de turmas do ano letivo vigente.'], 403);
        }

        $avaliacao = DB::table('avaliacoes')->where('id', $avaliacaoId)->where('turma_id', $turmaId)->first(['valor_maximo']);
        if (!$avaliacao) {
            return response()->json(['error' => 'Avaliação não encontrada para esta turma.'], 404);
        }
        if ($notaValor > (float) $avaliacao->valor_maximo) {
            return response()->json(['error' => "Nota ($notaValor) excede o valor máximo permitido ({$avaliacao->valor_maximo})."], 400);
        }

        $valorAnterior = null;

        if ($notaId > 0) {
            $valorAnterior = DB::table('notas')->where('id', $notaId)->where('aluno_id', $alunoId)->value('nota_valor');
            $valorAnterior = $valorAnterior !== null ? (float) $valorAnterior : null;

            $update = ['nota_valor' => $notaValor];
            if ($segundaChamada !== null) {
                $update['segunda_chamada'] = $segundaChamada;
            }
            DB::table('notas')->where('id', $notaId)->where('aluno_id', $alunoId)->update($update);
            $resultId = $notaId;
        } else {
            $resultId = DB::table('notas')->insertGetId([
                'aluno_id'         => $alunoId,
                'avaliacao_id'     => $avaliacaoId,
                'nota_valor'       => $notaValor,
                'segunda_chamada'  => $segundaChamada ?? 0,
            ]);
        }

        $this->log->registrar(
            $request, $usuarioId, $username, 'ATUALIZACAO_NOTA', '/api/notas', 'PUT',
            "Nota #$resultId atualizada para aluno #$alunoId, avaliação #$avaliacaoId. Valor: $notaValor",
            200,
            ['nota_id' => $resultId, 'aluno_id' => $alunoId, 'avaliacao_id' => $avaliacaoId, 'valor_anterior' => $valorAnterior, 'valor_novo' => round($notaValor, 2)]
        );

        return response()->json(['success' => true, 'nota_id' => $resultId, 'nota_valor' => round($notaValor, 2)]);
    }
}
