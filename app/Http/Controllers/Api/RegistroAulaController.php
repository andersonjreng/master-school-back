<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/registros_aula/*.php.
 */
class RegistroAulaController extends Controller
{
    public function __construct(private LogSistemaService $log)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = $jwtUser->id ?? null;
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $isProfessor = in_array('Professor', $funcoes, true);

        $turmaId = $request->query('turma_id');
        $data = $request->query('data');

        if (!$turmaId || !$data) {
            return response()->json(['error' => 'Parâmetros turma_id e data são obrigatórios.'], 400);
        }

        $registros = DB::select('
            SELECT
                ra.id,
                ra.turma_id,
                ra.professor_id,
                u.nome_completo AS professor_nome,
                ra.data,
                ra.disciplina,
                ra.conteudo,
                ra.numero_aulas,
                ra.observacoes
            FROM registros_aula ra
            JOIN professores p ON ra.professor_id = p.id
            JOIN usuarios u ON p.usuario_id = u.id
            WHERE ra.turma_id = ? AND ra.data = ?
            ORDER BY u.nome_completo ASC
        ', [$turmaId, $data]);

        foreach ($registros as $row) {
            $row->numero_aulas = (int) $row->numero_aulas;
        }

        // Resolve o professor_id (professores.id) do usuário logado, para o front
        // saber quais registros são "meus" sem precisar comparar contra usuario_id.
        $meuProfessorId = null;
        if ($isProfessor && $usuarioId) {
            $meuProfessorId = DB::table('professores')->where('usuario_id', $usuarioId)->value('id');
            $meuProfessorId = $meuProfessorId !== null ? (int) $meuProfessorId : null;
        }

        return response()->json([
            'success'          => true,
            'data_list'        => $registros,
            'meu_professor_id' => $meuProfessorId,
        ]);
    }

    public function resumoMensal(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $allowed = ['Administrador', 'Diretoria', 'Diretor', 'Professor'];
        if (empty(array_intersect($allowed, $funcoes))) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $turmaId = $request->query('turma_id');
        $ano = $request->query('ano');
        $mes = $request->query('mes');

        if (!$turmaId || !$ano) {
            return response()->json(['error' => 'Parâmetros turma_id e ano são obrigatórios.'], 400);
        }

        $sql = '
            SELECT
                data,
                COUNT(*) AS total_registros,
                GROUP_CONCAT(DISTINCT disciplina ORDER BY disciplina SEPARATOR \', \') AS disciplinas
            FROM registros_aula
            WHERE turma_id = ? AND YEAR(data) = ?
        ';
        $params = [$turmaId, $ano];

        if ($mes) {
            $sql .= ' AND MONTH(data) = ?';
            $params[] = $mes;
        }
        $sql .= ' GROUP BY data ORDER BY data ASC';

        $rows = DB::select($sql, $params);

        $dataList = array_map(fn ($row) => [
            'date'            => $row->data,
            'total_registros' => (int) $row->total_registros,
            'disciplinas'     => $row->disciplinas,
        ], $rows);

        return response()->json(['success' => true, 'data_list' => $dataList]);
    }

    public function salvar(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = $jwtUser->id ?? null;
        $username = $jwtUser->nome ?? 'desconhecido';
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $isProfessor = in_array('Professor', $funcoes, true);
        $isAdmin = in_array('Administrador', $funcoes, true) || in_array('Diretoria', $funcoes, true) || in_array('Diretor', $funcoes, true);

        if (!$isProfessor && !$isAdmin) {
            return response()->json([
                'error'   => 'Acesso negado.',
                'message' => 'Apenas professores, administradores ou diretoria podem registrar aulas.',
            ], 403);
        }

        $id = $request->input('id') ? (int) $request->input('id') : null;
        $turmaId = $request->input('turma_id');
        $dataAula = $request->input('data');
        $disciplina = trim((string) $request->input('disciplina', ''));
        $conteudo = trim((string) $request->input('conteudo', ''));
        $numeroAulas = (int) $request->input('numero_aulas', 1);
        $observacoes = $request->input('observacoes');

        if (!$turmaId || !$dataAula || $disciplina === '' || $conteudo === '') {
            return response()->json([
                'error'   => 'Campos obrigatórios faltando.',
                'message' => 'Turma, data, disciplina e conteúdo são obrigatórios.',
            ], 400);
        }

        if ($numeroAulas < 1) {
            $numeroAulas = 1;
        }

        // Resolve o professor logado (se houver vínculo na tabela professores)
        $professorIdLogado = null;
        if ($isProfessor) {
            $professorIdLogado = DB::table('professores')->where('usuario_id', $usuarioId)->value('id');
        }

        if ($id) {
            // ─── Edição de registro existente ──────────────────────────────
            $registroAtual = DB::table('registros_aula')->where('id', $id)->first(['professor_id']);

            if (!$registroAtual) {
                return response()->json(['error' => 'Registro de aula não encontrado.'], 404);
            }

            if (!$isAdmin && (int) $registroAtual->professor_id !== (int) $professorIdLogado) {
                return response()->json([
                    'error'   => 'Acesso negado.',
                    'message' => 'Você só pode editar registros de aula que você mesmo criou.',
                ], 403);
            }

            DB::table('registros_aula')->where('id', $id)->update([
                'disciplina'   => $disciplina,
                'conteudo'     => $conteudo,
                'numero_aulas' => $numeroAulas,
                'observacoes'  => $observacoes,
            ]);

            $registroId = $id;
            $acao = 'ATUALIZACAO_REGISTRO_AULA';
        } else {
            // ─── Criação de novo registro ───────────────────────────────────
            if (!$professorIdLogado) {
                return response()->json([
                    'error'   => 'Acesso negado.',
                    'message' => 'Apenas o professor responsável pode criar um novo registro de aula.',
                ], 403);
            }

            $existente = DB::table('registros_aula')
                ->where('turma_id', $turmaId)
                ->where('professor_id', $professorIdLogado)
                ->where('data', $dataAula)
                ->exists();

            if ($existente) {
                return response()->json([
                    'error'   => 'Registro duplicado.',
                    'message' => 'Você já possui um registro de aula para esta turma nesta data. Edite o registro existente.',
                ], 409);
            }

            $registroId = DB::table('registros_aula')->insertGetId([
                'turma_id'     => $turmaId,
                'professor_id' => $professorIdLogado,
                'data'         => $dataAula,
                'disciplina'   => $disciplina,
                'conteudo'     => $conteudo,
                'numero_aulas' => $numeroAulas,
                'observacoes'  => $observacoes,
            ]);

            $acao = 'CRIACAO_REGISTRO_AULA';
        }

        $this->log->registrar(
            $request,
            $usuarioId,
            $username,
            $acao,
            '/api/registros_aula/salvar',
            'POST',
            "Registro de aula #$registroId salvo (turma #$turmaId, data $dataAula)",
            200,
            ['registro_id' => $registroId, 'turma_id' => $turmaId, 'data' => $dataAula]
        );

        return response()->json([
            'success'     => true,
            'registro_id' => $registroId,
            'message'     => 'Registro de aula salvo com sucesso.',
        ]);
    }
}
