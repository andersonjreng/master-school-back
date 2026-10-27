<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/admin/{get_turmas_detalhes,post_turma,update_turma,
 * update_status_turma,get_alunos_por_turma_chart,get_ocupacao_turma,
 * post_aluno_turma,promover_alunos,transferir_aluno}.php.
 */
class TurmaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Papeis::ehEquipe($funcoes)) {
            return response()->json(['error' => 'Acesso negado. Você não tem permissão para listar as turmas.'], 403);
        }

        // ?minhas=1 → só as turmas em que o usuário logado leciona (tela Minhas
        // Turmas do professor). Sem o parâmetro, mantém a listagem completa.
        $professorId = null;
        if ($request->boolean('minhas')) {
            $professorId = DB::table('professores')->where('usuario_id', (int) ($jwtUser->id ?? 0))->value('id');
            if (!$professorId) {
                return response()->json(['success' => true, 'count' => 0, 'data' => []]);
            }
        }

        $turmas = DB::table('turmas as t')
            ->when($professorId, fn ($q) => $q->whereIn('t.id',
                DB::table('turma_professor_disciplina')->where('professor_id', $professorId)->select('turma_id')))
            ->leftJoinSub(
                DB::table('turma_aluno')->select('turma_id', 'aluno_id')->union(
                    DB::table('matriculas as m')->join('turmas as tm', 'tm.id', '=', 'm.turma_id')
                        ->where('tm.status', '<>', 'Ativa')
                        ->select('m.turma_id', 'm.aluno_id')
                ),
                'ta', 't.id', '=', 'ta.turma_id'
            )
            ->leftJoin('alunos as a', 'ta.aluno_id', '=', 'a.id')
            ->leftJoin('usuarios as ua', 'a.usuario_id', '=', 'ua.id')
            ->select([
                't.id as turma_id', 't.nome_turma', 't.ano_letivo', 't.turno', 't.capacidade', 't.status',
                't.sistema_avaliacao_id', 't.etapa_bncc',
                DB::raw("GROUP_CONCAT(DISTINCT ua.nome_completo ORDER BY ua.nome_completo ASC SEPARATOR ' ; ') AS alunos_vinculados"),
            ])
            ->groupBy('t.id', 't.nome_turma', 't.ano_letivo', 't.turno', 't.capacidade', 't.status', 't.sistema_avaliacao_id', 't.etapa_bncc')
            ->orderBy('t.nome_turma')
            ->get()
            ->keyBy('turma_id');

        $turmaIds = $turmas->keys()->all();
        $vinculos = [];
        if (!empty($turmaIds)) {
            $vinculos = DB::table('turma_professor_disciplina as tpd')
                ->join('disciplinas as d', 'tpd.disciplina_id', '=', 'd.id')
                ->leftJoin('professores as p', 'tpd.professor_id', '=', 'p.id')
                ->leftJoin('usuarios as up', 'p.usuario_id', '=', 'up.id')
                ->whereIn('tpd.turma_id', $turmaIds)
                ->orderBy('tpd.turma_id')
                ->orderBy('d.nome_disciplina')
                ->get(['tpd.turma_id', 'd.id as disciplina_id', 'd.nome_disciplina', 'tpd.carga_horaria_semanal', 'up.nome_completo as nome_professor']);
        }

        $output = [];
        foreach ($turmas as $turmaId => $row) {
            $arr = (array) $row;
            $arr['alunos_vinculados_array'] = !empty($row->alunos_vinculados) ? explode(' ; ', $row->alunos_vinculados) : [];
            unset($arr['alunos_vinculados']);
            $arr['disciplinas_professores'] = [];
            $output[$turmaId] = $arr;
        }
        foreach ($vinculos as $vinculo) {
            $output[$vinculo->turma_id]['disciplinas_professores'][] = [
                'disciplina_id' => $vinculo->disciplina_id,
                'nome_disciplina' => $vinculo->nome_disciplina,
                'carga_horaria_semanal' => $vinculo->carga_horaria_semanal,
                'professor_responsavel' => $vinculo->nome_professor,
            ];
        }

        $output = array_values($output);

        return response()->json(['success' => true, 'count' => count($output), 'data' => $output]);
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::TURMAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não pode criar turmas.'], 403);
        }

        $nomeTurma = $request->input('nome_turma');
        $anoLetivo = $request->input('ano_letivo');
        $turno = $request->input('turno');
        $capacidade = $request->input('capacidade');
        $sistemaAvaliacaoId = $request->input('sistema_avaliacao_id');
        $status = $request->input('status', 'Ativa');

        if (!$nomeTurma || !$anoLetivo || !$turno || !$capacidade || !$sistemaAvaliacaoId) {
            return response()->json(['error' => 'Os campos obrigatórios (nome_turma, ano_letivo, turno, capacidade, sistema_avaliacao_id) devem ser fornecidos.'], 400);
        }
        $anoLetivo = (int) $anoLetivo;

        // Nome é único por ano letivo ("5º Ano" pode existir em 2026 e em 2027).
        $duplicada = DB::table('turmas')->where('ano_letivo', $anoLetivo)
            ->whereRaw('LOWER(nome_turma) = ?', [mb_strtolower(trim($nomeTurma))])->exists();
        if ($duplicada) {
            return response()->json(['error' => "Já existe a turma \"$nomeTurma\" em $anoLetivo."], 409);
        }
        DB::table('anos_letivos')->insertOrIgnore(['ano' => $anoLetivo]);

        try {
            $turmaId = DB::transaction(function () use ($nomeTurma, $anoLetivo, $turno, $capacidade, $sistemaAvaliacaoId, $status) {
                $turmaId = DB::table('turmas')->insertGetId([
                    'nome_turma' => $nomeTurma,
                    'ano_letivo' => $anoLetivo,
                    'turno' => $turno,
                    'capacidade' => (int) $capacidade,
                    'sistema_avaliacao_id' => (int) $sistemaAvaliacaoId,
                    'status' => $status,
                ]);

                // Garante que o ano entra no catálogo central de anos letivos,
                // independente de já existirem bimestres pra ele ou não.
                DB::statement('INSERT IGNORE INTO anos_letivos (ano) VALUES (?)', [$anoLetivo]);

                // Se essa é a primeira turma desse ano_letivo, cria automaticamente
                // os 4 bimestres do ano — sem isso, boletim/notas/frequência não
                // funcionam pra ele.
                $temUnidades = DB::table('unidades_letivas')->where('ano_letivo', $anoLetivo)->exists();
                if (!$temUnidades) {
                    foreach (['1º Bimestre', '2º Bimestre', '3º Bimestre', '4º Bimestre'] as $nomeBimestre) {
                        DB::table('unidades_letivas')->insert([
                            'nome_unidade' => $nomeBimestre,
                            // NULL, não '0000-00-00': ver migration
                            // alter_unidades_letivas_datas_nullable.
                            'data_inicio' => null,
                            'data_fim' => null,
                            'ano_letivo' => $anoLetivo,
                            'ativo' => 1,
                        ]);
                    }
                }

                return $turmaId;
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha ao cadastrar a turma.', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => 'Turma cadastrada com sucesso.',
            'turma_id' => $turmaId,
            'nome_turma' => $nomeTurma,
            'status' => $status,
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::TURMAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não pode editar turmas.'], 403);
        }

        $turmaId = $request->input('turma_id');
        if (!$turmaId) {
            return response()->json(['error' => 'O campo turma_id é obrigatório.'], 400);
        }

        $campos = [];
        if ($request->has('nome_turma')) {
            $campos['nome_turma'] = $request->input('nome_turma');
        }
        if ($request->has('ano_letivo')) {
            $anoLetivoNovo = (int) $request->input('ano_letivo');
            $campos['ano_letivo'] = $anoLetivoNovo;
            DB::statement('INSERT IGNORE INTO anos_letivos (ano) VALUES (?)', [$anoLetivoNovo]);
        }
        if ($request->has('turno')) {
            $campos['turno'] = $request->input('turno');
        }
        if ($request->has('capacidade')) {
            $campos['capacidade'] = (int) $request->input('capacidade');
        }
        if ($request->has('sistema_avaliacao_id')) {
            $sistemaAvaliacaoId = (int) $request->input('sistema_avaliacao_id');
            $campos['sistema_avaliacao_id'] = $sistemaAvaliacaoId;

            // Quando o sistema muda para BNCC (id=3), deriva etapa_bncc a partir
            // do nome da turma (enviado na requisição ou o atual no banco).
            if ($sistemaAvaliacaoId === 3) {
                $nomeParaEtapa = $request->input('nome_turma');
                if (!$nomeParaEtapa) {
                    $nomeParaEtapa = DB::table('turmas')->where('id', $turmaId)->value('nome_turma') ?? '';
                }

                $etapaBncc = null;
                if (stripos($nomeParaEtapa, 'Berç') !== false) {
                    $etapaBncc = 'Bebês';
                } elseif (stripos($nomeParaEtapa, 'Maternal') !== false) {
                    $etapaBncc = 'Crianças bem pequenas';
                } elseif (
                    stripos($nomeParaEtapa, 'Período') !== false || stripos($nomeParaEtapa, 'Periodo') !== false ||
                    stripos($nomeParaEtapa, 'Jardim') !== false ||
                    stripos($nomeParaEtapa, 'Pré') !== false || stripos($nomeParaEtapa, 'Pre') !== false
                ) {
                    $etapaBncc = 'Crianças pequenas';
                }

                if ($etapaBncc !== null) {
                    $campos['etapa_bncc'] = $etapaBncc;
                }
            } else {
                $campos['etapa_bncc'] = null;
            }
        }
        if ($request->has('status')) {
            $campos['status'] = $request->input('status');
        }

        if (empty($campos)) {
            return response()->json(['error' => 'Nenhum campo para atualizar.'], 400);
        }
        if (!DB::table('turmas')->where('id', $turmaId)->exists()) {
            return response()->json(['error' => 'Turma não encontrada.'], 404);
        }

        DB::table('turmas')->where('id', $turmaId)->update($campos);

        return response()->json(['success' => 'Turma atualizada com sucesso.']);
    }

    /** Situação de uma turma (o front usa para entrar em modo somente leitura). */
    public function show(int $id): JsonResponse
    {
        $turma = DB::table('turmas')->where('id', $id)
            ->first(['id', 'nome_turma', 'ano_letivo', 'turno', 'status', 'encerrada_em', 'sistema_avaliacao_id', 'etapa_bncc']);
        if (!$turma) {
            return response()->json(['error' => 'Turma não encontrada.'], 404);
        }

        return response()->json(['success' => true, 'data' => [
            'id'                   => (int) $turma->id,
            'nome_turma'           => $turma->nome_turma,
            'ano_letivo'           => (int) $turma->ano_letivo,
            'turno'                => $turma->turno,
            'status'               => $turma->status,
            'encerrada_em'         => $turma->encerrada_em,
            'editavel'             => $turma->status === 'Ativa',
            'sistema_avaliacao_id' => $turma->sistema_avaliacao_id !== null ? (int) $turma->sistema_avaliacao_id : null,
            'etapa_bncc'           => $turma->etapa_bncc,
        ]]);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::TURMAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não pode alterar o status da turma.'], 403);
        }

        $turmaId = $request->query('turma_id');
        $status = $request->input('status');

        if (!$turmaId || !is_numeric($turmaId)) {
            return response()->json(['error' => 'ID da turma inválido ou não fornecido (turma_id).'], 400);
        }
        if (!$status) {
            return response()->json(['error' => 'O novo status deve ser fornecido no corpo da requisição.'], 400);
        }
        $validStatuses = ['Ativa', 'Inativa', 'Encerrada'];
        if (!in_array($status, $validStatuses, true)) {
            return response()->json(['error' => 'Status inválido. Use um dos seguintes: ' . implode(', ', $validStatuses)], 400);
        }

        // Encerrar = congelar a turma. Só depois que todos os alunos foram
        // promovidos/concluídos (senão eles ficariam presos numa turma congelada).
        if ($status === 'Encerrada') {
            $restantes = DB::table('turma_aluno as ta')->join('alunos as al', 'al.id', '=', 'ta.aluno_id')
                ->join('usuarios as ua', 'ua.id', '=', 'al.usuario_id')->where('ua.ativo', 1)
                ->where('ta.turma_id', $turmaId)->count();
            if ($restantes > 0) {
                return response()->json([
                    'error'   => 'Turma ainda tem alunos.',
                    'message' => "Esta turma ainda tem $restantes aluno(s) vinculado(s). Faça a promoção (promover, reter, concluir ou não renovou) antes de encerrar.",
                    'alunos_restantes' => $restantes,
                ], 409);
            }
        }

        $updated = DB::table('turmas')->where('id', $turmaId)->update([
            'status'       => $status,
            'encerrada_em' => $status === 'Encerrada' ? now() : null,
        ]);
        if ($updated > 0) {
            app(\App\Services\Sistema\LogSistemaService::class)->registrar(
                $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? 'desconhecido', 'STATUS_TURMA',
                '/api/admin/turmas/status', 'PATCH', "Turma #$turmaId alterada para $status", 200,
                ['turma_id' => (int) $turmaId, 'status' => $status]
            );
        }

        if ($updated === 0) {
            return response()->json(['error' => 'Turma não encontrada ou o status fornecido já estava aplicado.'], 404);
        }

        return response()->json([
            'success' => 'Status da turma alterado com sucesso.',
            'turma_id' => (int) $turmaId,
            'novo_status' => $status,
        ]);
    }

    public function alunosPorTurmaChart(): JsonResponse
    {
        $rows = DB::table('turmas as t')
            ->leftJoin('turma_aluno as ta', 't.id', '=', 'ta.turma_id')
            ->groupBy('t.id', 't.nome_turma')
            ->orderBy('t.nome_turma')
            ->get(['t.nome_turma', DB::raw('COUNT(ta.aluno_id) AS total_alunos')]);

        return response()->json([
            'labels' => $rows->pluck('nome_turma')->values(),
            'datasets' => [[
                'label' => 'Quantidade de Alunos',
                'data' => $rows->pluck('total_alunos')->map(fn ($v) => (int) $v)->values(),
                'backgroundColor' => ['#42A5F5', '#66BB6A', '#FFA726', '#FFD700', '#A020F0', '#FF6347'],
                'hoverBackgroundColor' => ['#64B5F6', '#81C784', '#FFB74D', '#FFF176', '#BA68C8', '#FF8A65'],
            ]],
        ]);
    }

    public function ocupacao(): JsonResponse
    {
        $rows = DB::table('turmas as t')
            ->leftJoin('turma_aluno as ta', 't.id', '=', 'ta.turma_id')
            ->groupBy('t.id', 't.nome_turma', 't.capacidade')
            ->orderBy('t.nome_turma')
            ->get(['t.nome_turma', 't.capacidade', DB::raw('COUNT(ta.aluno_id) AS total_alunos_ocupados')]);

        if ($rows->isEmpty()) {
            return response()->json(['error' => 'Nenhuma turma encontrada para o gráfico de ocupação.'], 404);
        }

        $labels = [];
        $ocupados = [];
        $vagas = [];
        foreach ($rows as $row) {
            $capacidade = (int) $row->capacidade;
            $alunosOcupados = (int) $row->total_alunos_ocupados;
            $labels[] = $row->nome_turma;
            $ocupados[] = $alunosOcupados;
            $vagas[] = max(0, $capacidade - $alunosOcupados);
        }

        return response()->json([
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Alunos Ocupados', 'data' => $ocupados, 'backgroundColor' => '#66BB6A', 'stack' => 'Capacidade'],
                ['label' => 'Vagas Restantes', 'data' => $vagas, 'backgroundColor' => '#FFA726', 'stack' => 'Capacidade'],
            ],
        ]);
    }

    public function vincularAlunos(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::TURMAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não pode vincular alunos a turmas.'], 403);
        }

        $turmaId = $request->input('turma_id');
        $alunosIds = $request->input('alunos_ids', []);

        if (!$turmaId || !is_numeric($turmaId)) {
            return response()->json(['error' => 'O ID da turma (turma_id) deve ser fornecido.'], 400);
        }
        if (empty($alunosIds) || !is_array($alunosIds)) {
            return response()->json(['error' => 'A lista de IDs dos alunos (alunos_ids) deve ser fornecida como um array não vazio.'], 400);
        }

        $alunosIdsValidos = array_values(array_filter($alunosIds, 'is_numeric'));
        if (empty($alunosIdsValidos)) {
            return response()->json(['error' => 'Nenhum aluno válido fornecido para vincular.'], 400);
        }

        if ($bloqueio = app(\App\Services\Turmas\TurmaSituacaoService::class)->bloqueio((int) $turmaId)) {
            return $bloqueio;
        }

        $avisos = [];
        $alunosParaInserir = [];
        foreach ($alunosIdsValidos as $alunoId) {
            $jaAtiva = DB::table('turma_aluno as ta')
                ->join('turmas as t', 'ta.turma_id', '=', 't.id')
                ->where('ta.aluno_id', $alunoId)
                ->where('t.status', 'Ativa')
                ->value('t.nome_turma');

            if ($jaAtiva) {
                $avisos[] = "Aluno ID $alunoId já está matriculado na turma ativa: $jaAtiva.";
            } else {
                $alunosParaInserir[] = (int) $alunoId;
            }
        }

        if (empty($alunosParaInserir)) {
            return response()->json([
                'error' => 'Nenhum aluno pode ser vinculado.',
                'total_solicitado' => count($alunosIdsValidos),
                'detalhes' => $avisos,
            ], 409);
        }

        try {
            $vinculosCriados = DB::transaction(function () use ($turmaId, $alunosParaInserir, &$avisos) {
                $criados = 0;
                foreach ($alunosParaInserir as $alunoId) {
                    try {
                        DB::table('turma_aluno')->insert(['turma_id' => $turmaId, 'aluno_id' => $alunoId]);
                        $criados++;

                        $numeros = app(\App\Services\Matriculas\NumeroMatriculaService::class);
                        $ano = (int) DB::table('turmas')->where('id', $turmaId)->value('ano_letivo');
                        DB::table('matriculas')->insert($numeros->linha([
                            'aluno_id' => $alunoId, 'turma_id' => $turmaId, 'ano_letivo' => $ano,
                            'data_inicio' => now()->toDateString(), 'status' => 'ativa', 'origem' => 'sistema',
                        ], $numeros->numeroDoAno($alunoId, $ano)));
                    } catch (\Illuminate\Database\QueryException $e) {
                        $errno = (int) ($e->errorInfo[1] ?? 0);
                        if ($errno === 1062) {
                            $avisos[] = "Vínculo (Turma ID $turmaId, Aluno ID $alunoId) ignorado: já existe (duplicidade no banco).";
                        } elseif ($errno === 1452) {
                            $avisos[] = "Vínculo (Turma ID $turmaId, Aluno ID $alunoId) ignorado: Turma ou Aluno não existem (Foreign Key).";
                        } else {
                            throw $e;
                        }
                    }
                }

                return $criados;
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha no vínculo transacional.', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => "$vinculosCriados alunos vinculados com sucesso à Turma ID $turmaId.",
            'turma_id' => (int) $turmaId,
            'vinculos_criados' => $vinculosCriados,
            'total_solicitado' => count($alunosIds),
            'total_inseridos' => count($alunosParaInserir),
            'avisos' => $avisos,
        ], 201);
    }

    /**
     * Encerramento do ano da turma (virada): destino de cada aluno — promover,
     * reter, concluir ou não renovou. Quem fez a rematrícula tem a reserva
     * efetivada aqui (turma, contrato e número do ano novo). Regras em
     * ViradaAnoService; cada aluno na sua transação, então um aluno com
     * problema não derruba o lote. Dá para mandar só parte da turma.
     *
     * encerrar_origem = true → encerra a turma de origem se ficar sem alunos.
     * Compatível com o formato antigo (promocoes[] com turma_destino_id).
     */
    public function promoverAlunos(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::TURMAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não pode promover alunos.'], 403);
        }

        $turmaOrigemId = (int) $request->input('turma_origem_id');
        $itens = $request->input('itens');
        if ($itens === null) {
            $itens = array_map(fn ($p) => ['aluno_id' => $p['aluno_id'] ?? null, 'acao' => 'promover', 'turma_destino_id' => $p['turma_destino_id'] ?? null],
                (array) $request->input('promocoes', []));
        }

        if (!$turmaOrigemId) {
            return response()->json(['error' => 'turma_origem_id é obrigatório.'], 400);
        }
        if (empty($itens) || !is_array($itens)) {
            return response()->json(['error' => 'Informe ao menos um aluno (itens).'], 400);
        }

        $origem = DB::table('turmas')->where('id', $turmaOrigemId)->first(['id', 'nome_turma', 'ano_letivo', 'status']);
        if (!$origem) {
            return response()->json(['error' => 'Turma de origem não encontrada.'], 404);
        }
        if ($origem->status !== 'Ativa') {
            return response()->json(['error' => 'A turma de origem precisa estar ativa. Reabra-a em Cadastros → Turmas se precisar ajustar a promoção.'], 409);
        }

        $virada = app(\App\Services\Turmas\ViradaAnoService::class);
        $dataFim = $virada->dataFim($origem);

        $resultado = ['promovidos' => 0, 'retidos' => 0, 'concluidos' => 0, 'nao_renovaram' => 0];
        $erros = [];

        foreach ($itens as $item) {
            $alunoId = (int) ($item['aluno_id'] ?? 0);
            $acao = $item['acao'] ?? 'promover';
            $destinoId = (int) ($item['turma_destino_id'] ?? 0);

            if (!$alunoId || !in_array($acao, ['promover', 'reter', 'concluir', 'nao_renovou'], true)) {
                $erros[] = ['aluno_id' => $alunoId, 'motivo' => 'Aluno ou ação inválida.'];
                continue;
            }

            try {
                $virada->processarAluno($origem, $alunoId, $acao, $destinoId, $dataFim, $usuarioIdLogado);
                $chave = ['promover' => 'promovidos', 'reter' => 'retidos', 'concluir' => 'concluidos', 'nao_renovou' => 'nao_renovaram'][$acao];
                $resultado[$chave]++;
            } catch (\Throwable $e) {
                $erros[] = ['aluno_id' => $alunoId, 'motivo' => $e->getMessage()];
            }
        }

        $restantes = $virada->contarRestantes($origem->id);
        $encerrada = false;
        if ($request->boolean('encerrar_origem') && $restantes === 0) {
            DB::table('turmas')->where('id', $origem->id)->update(['status' => 'Encerrada', 'encerrada_em' => now()]);
            $encerrada = true;
        }

        app(\App\Services\Sistema\LogSistemaService::class)->registrar(
            $request, $usuarioIdLogado, $jwtUser->nome ?? 'desconhecido', 'PROMOCAO_ALUNOS', '/api/admin/turmas/promover-alunos', 'POST',
            "Virada da turma {$origem->nome_turma} ({$origem->ano_letivo}): " . json_encode($resultado) . ($encerrada ? ' — turma encerrada' : ''),
            200,
            ['turma_origem_id' => $origem->id] + $resultado + ['erros' => count($erros)]
        );

        return response()->json([
            'success'          => true,
            'total_solicitado' => count($itens),
            'total_promovidos' => array_sum($resultado),
            'resultado'        => $resultado,
            'erros'            => $erros,
            'alunos_restantes' => $restantes,
            'turma_encerrada'  => $encerrada,
        ]);
    }

    public function transferirAluno(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!\App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::TURMAS_GERENCIAR)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não pode transferir alunos.'], 403);
        }

        $alunoId = $request->input('aluno_id');
        $novaTurmaId = $request->input('nova_turma_id');
        $turmaAntigaId = $request->input('turma_antiga_id');

        if (!$alunoId || !$novaTurmaId) {
            return response()->json(['error' => 'aluno_id e nova_turma_id são obrigatórios.'], 400);
        }

        try {
            DB::transaction(function () use ($alunoId, $novaTurmaId, $turmaAntigaId) {
                $turma = DB::table('turmas')->where('id', $novaTurmaId)->value('status');
                if (!$turma || $turma !== 'Ativa') {
                    throw new \Exception('A turma de destino não existe ou não está ativa.');
                }

                // Descobre a turma de origem pra fechar a matrícula histórica. Se
                // turma_antiga_id não foi enviado, usa a matrícula 'ativa' atual
                // como fonte da verdade.
                $origemTurmaId = $turmaAntigaId ? (int) $turmaAntigaId : null;
                if (!$origemTurmaId) {
                    $origemTurmaId = DB::table('matriculas')
                        ->where('aluno_id', $alunoId)->where('status', 'ativa')
                        ->orderByDesc('id')->value('turma_id');
                }

                if ($turmaAntigaId) {
                    $atualizados = DB::table('turma_aluno')
                        ->where('turma_id', $turmaAntigaId)->where('aluno_id', $alunoId)
                        ->update(['turma_id' => $novaTurmaId]);
                } else {
                    // Sem turma_antiga_id, atualiza a única linha do aluno (LIMIT 1,
                    // igual ao legado — turma_aluno não tem ORDER BY natural aqui).
                    $atualizados = DB::affectingStatement(
                        'UPDATE turma_aluno SET turma_id = ? WHERE aluno_id = ? LIMIT 1',
                        [$novaTurmaId, $alunoId]
                    );
                }

                if ($atualizados === 0) {
                    throw new \Exception('Nenhum registro de matrícula encontrado para transferir ou o aluno já está nessa turma.');
                }

                if ($origemTurmaId) {
                    DB::table('matriculas')
                        ->where('aluno_id', $alunoId)->where('turma_id', $origemTurmaId)->where('status', 'ativa')
                        ->update(['status' => 'transferida', 'data_fim' => now()->toDateString()]);
                }

                // Troca de turma no mesmo ano: o número de matrícula continua o mesmo.
                DB::table('matriculas')->insert(app(\App\Services\Matriculas\NumeroMatriculaService::class)->linha([
                    'aluno_id' => $alunoId, 'turma_id' => $novaTurmaId,
                    'ano_letivo' => DB::table('turmas')->where('id', $novaTurmaId)->value('ano_letivo'),
                    'data_inicio' => now()->toDateString(), 'status' => 'ativa', 'origem' => 'sistema',
                ], DB::table('alunos')->where('id', $alunoId)->value('matricula')));
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Erro na transferência.', 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => 'Aluno transferido com sucesso para a nova turma.',
            'aluno_id' => $alunoId,
            'nova_turma_id' => $novaTurmaId,
        ]);
    }
}
