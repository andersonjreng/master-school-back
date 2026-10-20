<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Avaliacoes\ModeloAvaliacaoService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Parametrização dos modelos de avaliação (Configurações → Parametrizações).
 *
 * Um modelo é um grupo de tipos (nome, peso, cor) cujos pesos somam 100 —
 * 10 pontos na média bimestral. Vale para todas as turmas ou para turmas
 * selecionadas.
 *
 * Proteção do histórico: se algum tipo do modelo já tem avaliações, a
 * composição (quais tipos e seus pesos) fica travada — mudar alteraria médias
 * já calculadas. Continua permitido renomear, trocar cores, turmas e ativar/
 * desativar. Modelos padrão ou em uso não são excluídos.
 */
class ModeloAvaliacaoController extends Controller
{
    private const COR_HEX = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(private LogSistemaService $log)
    {
    }

    public function index(): JsonResponse
    {
        $modelos = DB::table('modelos_avaliacao')->orderByDesc('padrao')->orderBy('nome')->get();
        $tipos = DB::table('tipos_avaliacao as t')
            ->leftJoin('avaliacoes as a', 'a.tipo_avaliacao_id', '=', 't.id')
            ->groupBy('t.id', 't.modelo_avaliacao_id', 't.nome_tipo', 't.peso', 't.cor', 't.ordem')
            ->orderBy('t.ordem')
            ->get(['t.id', 't.modelo_avaliacao_id', 't.nome_tipo', 't.peso', 't.cor', 't.ordem', DB::raw('COUNT(a.id) AS total_avaliacoes')])
            ->groupBy('modelo_avaliacao_id');
        $vinculos = DB::table('modelo_avaliacao_turma')->get()->groupBy('modelo_avaliacao_id');

        $data = $modelos->map(function ($m) use ($tipos, $vinculos) {
            $tiposModelo = ($tipos[$m->id] ?? collect())->map(fn ($t) => [
                'id'               => (int) $t->id,
                'nome_tipo'        => $t->nome_tipo,
                'peso'             => (int) $t->peso,
                'cor'              => $t->cor,
                'total_avaliacoes' => (int) $t->total_avaliacoes,
            ])->values();

            return [
                'id'               => (int) $m->id,
                'nome'             => $m->nome,
                'descricao'        => $m->descricao,
                'padrao'           => (bool) $m->padrao,
                'ativo'            => (bool) $m->ativo,
                'todas_turmas'     => (bool) $m->todas_turmas,
                'turma_ids'        => ($vinculos[$m->id] ?? collect())->pluck('turma_id')->map(fn ($id) => (int) $id)->values(),
                'tipos'            => $tiposModelo,
                'peso_total'       => $tiposModelo->sum('peso'),
                'total_avaliacoes' => $tiposModelo->sum('total_avaliacoes'),
            ];
        });

        return response()->json(['success' => true, 'peso_total' => ModeloAvaliacaoService::PESO_TOTAL, 'data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $this->validar($request);
        if ($dados instanceof JsonResponse) {
            return $dados;
        }

        $id = DB::transaction(function () use ($dados) {
            $id = DB::table('modelos_avaliacao')->insertGetId([
                'nome'         => $dados['nome'],
                'descricao'    => $dados['descricao'],
                'padrao'       => 0,
                'ativo'        => $dados['ativo'],
                'todas_turmas' => $dados['todas_turmas'],
            ]);
            foreach ($dados['tipos'] as $ordem => $tipo) {
                DB::table('tipos_avaliacao')->insert([
                    'modelo_avaliacao_id' => $id,
                    'nome_tipo'           => $tipo['nome_tipo'],
                    'peso'                => $tipo['peso'],
                    'soma_maxima'         => ModeloAvaliacaoService::PESO_TOTAL,
                    'cor'                 => $tipo['cor'],
                    'ordem'               => $ordem,
                ]);
            }
            $this->sincronizarTurmas($id, $dados['todas_turmas'] ? [] : $dados['turma_ids']);
            return $id;
        });

        $this->registrarLog($request, 'CRIACAO_MODELO_AVALIACAO', 'POST', "Modelo de avaliação #$id criado: \"{$dados['nome']}\"", ['modelo_avaliacao_id' => $id]);

        return response()->json(['success' => true, 'id' => $id, 'message' => 'Modelo de avaliação criado.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!DB::table('modelos_avaliacao')->where('id', $id)->exists()) {
            return response()->json(['error' => 'Modelo de avaliação não encontrado.'], 404);
        }

        $dados = $this->validar($request, $id);
        if ($dados instanceof JsonResponse) {
            return $dados;
        }

        $atuais = DB::table('tipos_avaliacao')->where('modelo_avaliacao_id', $id)->get(['id', 'peso'])->keyBy('id');
        $emUso = $this->totalAvaliacoes($id) > 0;

        // Tipos enviados com id precisam ser deste modelo.
        foreach ($dados['tipos'] as $tipo) {
            if ($tipo['id'] && !$atuais->has($tipo['id'])) {
                return response()->json(['error' => 'Tipo de avaliação não pertence a este modelo.'], 400);
            }
        }

        if ($emUso) {
            $idsEnviados = array_filter(array_column($dados['tipos'], 'id'));
            $mudouComposicao = count($dados['tipos']) !== $atuais->count()
                || count($idsEnviados) !== $atuais->count()
                || collect($dados['tipos'])->contains(fn ($t) => (int) $atuais[$t['id']]->peso !== $t['peso']);
            if ($mudouComposicao) {
                return response()->json([
                    'error'   => 'Composição bloqueada.',
                    'message' => 'Este modelo já tem avaliações lançadas. Os tipos e pesos não podem mudar, pois alterariam médias já calculadas — crie um novo modelo com a composição desejada.',
                ], 409);
            }
        }

        DB::transaction(function () use ($id, $dados, $atuais) {
            DB::table('modelos_avaliacao')->where('id', $id)->update([
                'nome'         => $dados['nome'],
                'descricao'    => $dados['descricao'],
                'ativo'        => $dados['ativo'],
                'todas_turmas' => $dados['todas_turmas'],
            ]);

            $mantidos = [];
            foreach ($dados['tipos'] as $ordem => $tipo) {
                $campos = ['nome_tipo' => $tipo['nome_tipo'], 'peso' => $tipo['peso'], 'cor' => $tipo['cor'], 'ordem' => $ordem];
                if ($tipo['id']) {
                    DB::table('tipos_avaliacao')->where('id', $tipo['id'])->update($campos);
                    $mantidos[] = $tipo['id'];
                } else {
                    $mantidos[] = DB::table('tipos_avaliacao')->insertGetId($campos + [
                        'modelo_avaliacao_id' => $id,
                        'soma_maxima'         => ModeloAvaliacaoService::PESO_TOTAL,
                    ]);
                }
            }
            // Só chega aqui com tipos removidos se o modelo não está em uso.
            $removidos = $atuais->keys()->diff($mantidos)->all();
            if ($removidos) {
                DB::table('tipos_avaliacao')->whereIn('id', $removidos)->delete();
            }

            $this->sincronizarTurmas($id, $dados['todas_turmas'] ? [] : $dados['turma_ids']);
        });

        $this->registrarLog($request, 'ATUALIZACAO_MODELO_AVALIACAO', 'PUT', "Modelo de avaliação #$id atualizado: \"{$dados['nome']}\"", ['modelo_avaliacao_id' => $id]);

        return response()->json(['success' => true, 'message' => 'Modelo de avaliação atualizado.']);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $modelo = DB::table('modelos_avaliacao')->where('id', $id)->first(['nome']);
        if (!$modelo) {
            return response()->json(['error' => 'Modelo de avaliação não encontrado.'], 404);
        }

        $ativo = filter_var($request->input('ativo'), FILTER_VALIDATE_BOOLEAN);
        DB::table('modelos_avaliacao')->where('id', $id)->update(['ativo' => $ativo ? 1 : 0]);

        $this->registrarLog($request, 'STATUS_MODELO_AVALIACAO', 'PATCH', "Modelo de avaliação #$id \"{$modelo->nome}\" " . ($ativo ? 'ativado' : 'desativado'), ['modelo_avaliacao_id' => $id, 'ativo' => $ativo]);

        return response()->json(['success' => true, 'ativo' => $ativo]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $modelo = DB::table('modelos_avaliacao')->where('id', $id)->first(['nome', 'padrao']);
        if (!$modelo) {
            return response()->json(['error' => 'Modelo de avaliação não encontrado.'], 404);
        }
        if ($modelo->padrao) {
            return response()->json(['error' => 'Modelos padrão não podem ser excluídos. Desative-o ou limite as turmas em que ele vale.'], 409);
        }
        if ($this->totalAvaliacoes($id) > 0) {
            return response()->json(['error' => 'Este modelo já tem avaliações lançadas. Desative-o em vez de excluir.'], 409);
        }

        DB::transaction(function () use ($id) {
            DB::table('modelo_avaliacao_turma')->where('modelo_avaliacao_id', $id)->delete();
            DB::table('tipos_avaliacao')->where('modelo_avaliacao_id', $id)->delete();
            DB::table('modelos_avaliacao')->where('id', $id)->delete();
        });

        $this->registrarLog($request, 'EXCLUSAO_MODELO_AVALIACAO', 'DELETE', "Modelo de avaliação #$id excluído: \"{$modelo->nome}\"", ['modelo_avaliacao_id' => $id]);

        return response()->json(['success' => true]);
    }

    /** @return array<string, mixed>|JsonResponse */
    private function validar(Request $request, ?int $id = null): array|JsonResponse
    {
        $nome = trim((string) $request->input('nome', ''));
        $descricao = trim((string) $request->input('descricao', '')) ?: null;
        $todas = filter_var($request->input('todas_turmas', true), FILTER_VALIDATE_BOOLEAN);
        $turmaIds = array_values(array_unique(array_map('intval', (array) $request->input('turma_ids', []))));
        $tiposEntrada = (array) $request->input('tipos', []);

        if ($nome === '' || mb_strlen($nome) > 100) {
            return response()->json(['error' => 'Informe o nome do modelo (até 100 caracteres).'], 400);
        }
        $duplicado = DB::table('modelos_avaliacao')
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome)])
            ->when($id, fn ($q) => $q->where('id', '<>', $id))
            ->exists();
        if ($duplicado) {
            return response()->json(['error' => "Já existe um modelo chamado \"$nome\"."], 409);
        }

        if (!$tiposEntrada) {
            return response()->json(['error' => 'Adicione ao menos um tipo de avaliação ao modelo.'], 400);
        }

        $tipos = [];
        $nomes = [];
        foreach ($tiposEntrada as $t) {
            $nomeTipo = trim((string) ($t['nome_tipo'] ?? ''));
            $peso = $t['peso'] ?? null;
            $cor = $t['cor'] ?? null;
            if ($nomeTipo === '' || mb_strlen($nomeTipo) > 100) {
                return response()->json(['error' => 'Todo tipo precisa de um nome (até 100 caracteres).'], 400);
            }
            if (in_array(mb_strtolower($nomeTipo), $nomes, true)) {
                return response()->json(['error' => "O tipo \"$nomeTipo\" aparece mais de uma vez no modelo."], 400);
            }
            if (!is_numeric($peso) || (int) $peso != $peso || $peso < 1 || $peso > 100) {
                return response()->json(['error' => "O peso de \"$nomeTipo\" deve ser um inteiro entre 1 e 100."], 400);
            }
            if ($cor !== null && $cor !== '' && !preg_match(self::COR_HEX, $cor)) {
                return response()->json(['error' => "Cor inválida em \"$nomeTipo\"."], 400);
            }
            $nomes[] = mb_strtolower($nomeTipo);
            $tipos[] = ['id' => !empty($t['id']) ? (int) $t['id'] : null, 'nome_tipo' => $nomeTipo, 'peso' => (int) $peso, 'cor' => $cor ?: null];
        }

        $soma = array_sum(array_column($tipos, 'peso'));
        if ($soma !== ModeloAvaliacaoService::PESO_TOTAL) {
            $pontos = rtrim(rtrim(number_format($soma / 10, 1, ',', ''), '0'), ',');
            return response()->json([
                'error' => "Os pesos somam $soma (= $pontos pontos). Um modelo precisa somar exatamente 100 (= 10 pontos) para a média fechar em 10.",
            ], 400);
        }

        if (!$todas && !$turmaIds) {
            return response()->json(['error' => 'Selecione ao menos uma turma ou marque "Todas as turmas".'], 400);
        }
        if (!$todas && DB::table('turmas')->whereIn('id', $turmaIds)->count() !== count($turmaIds)) {
            return response()->json(['error' => 'Uma ou mais turmas selecionadas não existem.'], 400);
        }

        return [
            'nome'         => $nome,
            'descricao'    => $descricao,
            'ativo'        => filter_var($request->input('ativo', true), FILTER_VALIDATE_BOOLEAN),
            'todas_turmas' => $todas,
            'turma_ids'    => $turmaIds,
            'tipos'        => $tipos,
        ];
    }

    private function sincronizarTurmas(int $modeloId, array $turmaIds): void
    {
        DB::table('modelo_avaliacao_turma')->where('modelo_avaliacao_id', $modeloId)->delete();
        if ($turmaIds) {
            DB::table('modelo_avaliacao_turma')->insert(
                array_map(fn ($turmaId) => ['modelo_avaliacao_id' => $modeloId, 'turma_id' => $turmaId], $turmaIds)
            );
        }
    }

    private function totalAvaliacoes(int $modeloId): int
    {
        return DB::table('avaliacoes as a')
            ->join('tipos_avaliacao as t', 't.id', '=', 'a.tipo_avaliacao_id')
            ->where('t.modelo_avaliacao_id', $modeloId)
            ->count();
    }

    private function registrarLog(Request $request, string $acao, string $metodo, string $descricao, array $extra): void
    {
        $jwtUser = $request->attributes->get('max_user');
        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? 'desconhecido', $acao,
            '/api/parametrizacoes/modelos-avaliacao', $metodo, $descricao, 200, $extra
        );
    }
}
