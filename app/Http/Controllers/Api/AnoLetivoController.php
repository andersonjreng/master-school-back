<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Anos letivos (Configurações → Parametrizações → Ano letivo): o admin cria o
 * ano, seus bimestres (unidades letivas) e semestres BNCC, duplica as turmas
 * do ano anterior (levando professores e modelos de avaliação) e encerra as
 * turmas no fim do ano — sem depender de ajuste direto no banco.
 */
class AnoLetivoController extends Controller
{
    public function __construct(private LogSistemaService $log)
    {
    }

    public function index(): JsonResponse
    {
        $anos = DB::table('anos_letivos')->orderByDesc('ano')->pluck('ano')->map(fn ($a) => (int) $a);

        $turmas = DB::table('turmas')
            ->select('ano_letivo', 'status', DB::raw('COUNT(*) AS total'))
            ->groupBy('ano_letivo', 'status')
            ->get()
            ->groupBy('ano_letivo');

        $unidades = DB::table('unidades_letivas as u')
            ->leftJoin('avaliacoes as a', 'a.unidade_letiva_id', '=', 'u.id')
            ->groupBy('u.id', 'u.nome_unidade', 'u.data_inicio', 'u.data_fim', 'u.ano_letivo', 'u.ativo')
            ->orderBy('u.id')
            ->get(['u.id', 'u.nome_unidade', 'u.data_inicio', 'u.data_fim', 'u.ano_letivo', 'u.ativo', DB::raw('COUNT(a.id) AS total_avaliacoes')])
            ->groupBy('ano_letivo');

        $semestres = DB::table('semestres as s')
            ->leftJoin('registros_bncc as r', 'r.semestre_id', '=', 's.id')
            ->groupBy('s.id', 's.nome', 's.ano_letivo', 's.data_inicio', 's.data_fim')
            ->orderBy('s.id')
            ->get(['s.id', 's.nome', 's.ano_letivo', 's.data_inicio', 's.data_fim', DB::raw('COUNT(r.id) AS total_registros')])
            ->groupBy('ano_letivo');

        $data = $anos->map(function ($ano) use ($turmas, $unidades, $semestres) {
            $porStatus = collect($turmas[$ano] ?? [])->pluck('total', 'status');
            return [
                'ano'     => $ano,
                'turmas'  => [
                    'total'      => (int) $porStatus->sum(),
                    'ativas'     => (int) ($porStatus['Ativa'] ?? 0),
                    'encerradas' => (int) ($porStatus['Encerrada'] ?? 0),
                    'inativas'   => (int) ($porStatus['Inativa'] ?? 0),
                ],
                'unidades' => collect($unidades[$ano] ?? [])->map(fn ($u) => [
                    'id'               => (int) $u->id,
                    'nome'             => $u->nome_unidade,
                    'data_inicio'      => $this->data($u->data_inicio),
                    'data_fim'         => $this->data($u->data_fim),
                    'total_avaliacoes' => (int) $u->total_avaliacoes,
                ])->values(),
                'semestres' => collect($semestres[$ano] ?? [])->map(fn ($s) => [
                    'id'              => (int) $s->id,
                    'nome'            => $s->nome,
                    'data_inicio'     => $this->data($s->data_inicio),
                    'data_fim'        => $this->data($s->data_fim),
                    'total_registros' => (int) $s->total_registros,
                ])->values(),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $ano = (int) $request->input('ano');
        if ($ano < 2000 || $ano > 2100) {
            return response()->json(['error' => 'Informe um ano válido.'], 400);
        }
        if (DB::table('anos_letivos')->where('ano', $ano)->exists()) {
            return response()->json(['error' => "O ano letivo $ano já existe."], 409);
        }

        $periodos = $this->validarPeriodos($request, $ano);
        if ($periodos instanceof JsonResponse) {
            return $periodos;
        }

        DB::transaction(function () use ($ano, $periodos) {
            DB::table('anos_letivos')->insert(['ano' => $ano]);
            $this->sincronizarPeriodos($ano, $periodos);
            // Calendário escolar já nasce com os feriados nacionais do ano.
            app(\App\Services\Turmas\CalendarioEscolarService::class)->importarNacionais($ano);
        });

        $this->registrar($request, 'CRIACAO_ANO_LETIVO', 'POST', "Ano letivo $ano criado", ['ano' => $ano]);

        return response()->json(['success' => true, 'message' => "Ano letivo $ano criado."], 201);
    }

    public function update(Request $request, int $ano): JsonResponse
    {
        if (!DB::table('anos_letivos')->where('ano', $ano)->exists()) {
            return response()->json(['error' => 'Ano letivo não encontrado.'], 404);
        }

        $periodos = $this->validarPeriodos($request, $ano);
        if ($periodos instanceof JsonResponse) {
            return $periodos;
        }

        try {
            DB::transaction(fn () => $this->sincronizarPeriodos($ano, $periodos));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        $this->registrar($request, 'ATUALIZACAO_ANO_LETIVO', 'PUT', "Bimestres/semestres do ano $ano atualizados", ['ano' => $ano]);

        return response()->json(['success' => true, 'message' => "Ano letivo $ano atualizado."]);
    }

    /**
     * Cria no ano $ano cópias das turmas escolhidas do ano de origem: mesmo
     * turno, capacidade, sistema de avaliação e etapa BNCC; opcionalmente os
     * vínculos professor × disciplina e os modelos de avaliação da turma.
     * Alunos NÃO são copiados — eles chegam pela promoção.
     */
    public function duplicarTurmas(Request $request, int $ano): JsonResponse
    {
        if (!DB::table('anos_letivos')->where('ano', $ano)->exists()) {
            return response()->json(['error' => "Crie o ano letivo $ano antes de duplicar turmas."], 400);
        }

        $itens = (array) $request->input('turmas', []);
        $copiarProfessores = $request->boolean('copiar_professores', true);
        if (!$itens) {
            return response()->json(['error' => 'Selecione ao menos uma turma.'], 400);
        }

        $criadas = [];
        $erros = [];
        foreach ($itens as $item) {
            $origemId = (int) ($item['turma_id'] ?? 0);
            $nome = trim((string) ($item['nome_turma'] ?? ''));
            $origem = DB::table('turmas')->where('id', $origemId)->first();

            if (!$origem || $nome === '') {
                $erros[] = ['turma_id' => $origemId, 'motivo' => 'Turma de origem ou nome inválido.'];
                continue;
            }
            if (DB::table('turmas')->where('ano_letivo', $ano)->whereRaw('LOWER(nome_turma) = ?', [mb_strtolower($nome)])->exists()) {
                $erros[] = ['turma_id' => $origemId, 'nome_turma' => $nome, 'motivo' => "Já existe a turma \"$nome\" em $ano."];
                continue;
            }

            $novoId = DB::transaction(function () use ($origem, $nome, $ano, $copiarProfessores) {
                $novoId = DB::table('turmas')->insertGetId([
                    'nome_turma'           => $nome,
                    'turno'                => $origem->turno,
                    'capacidade'           => $origem->capacidade,
                    'status'               => 'Ativa',
                    'ano_letivo'           => $ano,
                    'sistema_avaliacao_id' => $origem->sistema_avaliacao_id,
                    'etapa_bncc'           => $origem->etapa_bncc,
                ]);

                $vinculos = DB::table('turma_professor_disciplina')->where('turma_id', $origem->id)->get();
                foreach ($vinculos as $v) {
                    DB::table('turma_professor_disciplina')->insert([
                        'turma_id'              => $novoId,
                        'disciplina_id'         => $v->disciplina_id,
                        'professor_id'          => $copiarProfessores ? $v->professor_id : null,
                        'carga_horaria_semanal' => $v->carga_horaria_semanal,
                    ]);
                }

                $modelos = DB::table('modelo_avaliacao_turma')->where('turma_id', $origem->id)->pluck('modelo_avaliacao_id');
                foreach ($modelos as $modeloId) {
                    DB::table('modelo_avaliacao_turma')->insertOrIgnore(['modelo_avaliacao_id' => $modeloId, 'turma_id' => $novoId]);
                }

                return $novoId;
            });

            $criadas[] = ['turma_id' => $novoId, 'nome_turma' => $nome, 'origem_id' => (int) $origem->id];
        }

        $this->registrar($request, 'DUPLICACAO_TURMAS', 'POST',
            count($criadas) . " turma(s) criadas em $ano a partir do ano anterior", ['ano' => $ano, 'criadas' => count($criadas), 'erros' => count($erros)]);

        return response()->json(['success' => true, 'criadas' => $criadas, 'erros' => $erros]);
    }

    /** Encerra (congela) as turmas ativas do ano que já não têm alunos vinculados. */
    public function encerrarTurmas(Request $request, int $ano): JsonResponse
    {
        $turmas = DB::table('turmas as t')
            ->where('t.ano_letivo', $ano)
            ->where('t.status', 'Ativa')
            ->get(['t.id', 't.nome_turma', DB::raw('(SELECT COUNT(*) FROM turma_aluno ta JOIN alunos al ON al.id = ta.aluno_id JOIN usuarios ua ON ua.id = al.usuario_id WHERE ta.turma_id = t.id AND ua.ativo = 1) AS alunos')]);

        $encerradas = [];
        $pendentes = [];
        foreach ($turmas as $t) {
            if ((int) $t->alunos > 0) {
                $pendentes[] = ['turma_id' => (int) $t->id, 'nome_turma' => $t->nome_turma, 'alunos' => (int) $t->alunos];
                continue;
            }
            DB::table('turmas')->where('id', $t->id)->update(['status' => 'Encerrada', 'encerrada_em' => now()]);
            $encerradas[] = ['turma_id' => (int) $t->id, 'nome_turma' => $t->nome_turma];
        }

        $this->registrar($request, 'ENCERRAMENTO_TURMAS', 'POST',
            count($encerradas) . " turma(s) de $ano encerradas; " . count($pendentes) . ' com alunos pendentes', ['ano' => $ano]);

        return response()->json(['success' => true, 'encerradas' => $encerradas, 'pendentes' => $pendentes]);
    }

    /** @return array{unidades: array, semestres: array}|JsonResponse */
    private function validarPeriodos(Request $request, int $ano): array|JsonResponse
    {
        $resultado = [];
        foreach (['unidades' => 'bimestre', 'semestres' => 'semestre'] as $campo => $rotulo) {
            $lista = [];
            $nomes = [];
            foreach ((array) $request->input($campo, []) as $p) {
                $nome = trim((string) ($p['nome'] ?? ''));
                $ini = $p['data_inicio'] ?? null;
                $fim = $p['data_fim'] ?? null;
                if ($nome === '') {
                    return response()->json(['error' => "Todo $rotulo precisa de um nome."], 400);
                }
                if (in_array(mb_strtolower($nome), $nomes, true)) {
                    return response()->json(['error' => "O $rotulo \"$nome\" está repetido."], 400);
                }
                foreach ([$ini, $fim] as $d) {
                    if ($d && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                        return response()->json(['error' => "Data inválida em \"$nome\"."], 400);
                    }
                }
                if ($ini && $fim && $fim < $ini) {
                    return response()->json(['error' => "\"$nome\" termina antes de começar."], 400);
                }
                $nomes[] = mb_strtolower($nome);
                $lista[] = ['id' => !empty($p['id']) ? (int) $p['id'] : null, 'nome' => $nome, 'data_inicio' => $ini ?: null, 'data_fim' => $fim ?: null];
            }
            $resultado[$campo] = $lista;
        }

        if (!$resultado['unidades']) {
            return response()->json(['error' => 'O ano letivo precisa de ao menos um bimestre.'], 400);
        }

        return $resultado;
    }

    private function sincronizarPeriodos(int $ano, array $periodos): void
    {
        // Bimestres (unidades_letivas)
        $atuais = DB::table('unidades_letivas')->where('ano_letivo', $ano)->pluck('id')->map(fn ($id) => (int) $id);
        $mantidos = [];
        foreach ($periodos['unidades'] as $u) {
            $campos = ['nome_unidade' => $u['nome'], 'data_inicio' => $u['data_inicio'], 'data_fim' => $u['data_fim']];
            if ($u['id'] && $atuais->contains($u['id'])) {
                DB::table('unidades_letivas')->where('id', $u['id'])->update($campos);
                $mantidos[] = $u['id'];
            } else {
                $mantidos[] = DB::table('unidades_letivas')->insertGetId($campos + ['ano_letivo' => $ano, 'ativo' => 1]);
            }
        }
        $remover = $atuais->diff($mantidos);
        if ($remover->isNotEmpty()) {
            if (DB::table('avaliacoes')->whereIn('unidade_letiva_id', $remover)->exists()) {
                throw new \RuntimeException('Não é possível remover um bimestre que já tem avaliações.');
            }
            DB::table('unidades_letivas')->whereIn('id', $remover)->delete();
        }

        // Semestres (BNCC)
        $atuais = DB::table('semestres')->where('ano_letivo', $ano)->pluck('id')->map(fn ($id) => (int) $id);
        $mantidos = [];
        foreach ($periodos['semestres'] as $s) {
            $campos = ['nome' => $s['nome'], 'data_inicio' => $s['data_inicio'], 'data_fim' => $s['data_fim']];
            if ($s['id'] && $atuais->contains($s['id'])) {
                DB::table('semestres')->where('id', $s['id'])->update($campos);
                $mantidos[] = $s['id'];
            } else {
                $mantidos[] = DB::table('semestres')->insertGetId($campos + ['ano_letivo' => $ano]);
            }
        }
        $remover = $atuais->diff($mantidos);
        if ($remover->isNotEmpty()) {
            if (DB::table('registros_bncc')->whereIn('semestre_id', $remover)->exists()) {
                throw new \RuntimeException('Não é possível remover um semestre que já tem registros BNCC.');
            }
            DB::table('semestres')->whereIn('id', $remover)->delete();
        }
    }

    /** Datas zeradas do banco legado ('0000-00-00') viram null. */
    private function data(?string $d): ?string
    {
        return ($d && $d !== '0000-00-00') ? $d : null;
    }

    private function registrar(Request $request, string $acao, string $metodo, string $descricao, array $extra): void
    {
        $jwtUser = $request->attributes->get('max_user');
        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? 'desconhecido', $acao,
            '/api/parametrizacoes/anos-letivos', $metodo, $descricao, 200, $extra
        );
    }
}
