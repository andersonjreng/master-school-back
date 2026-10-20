<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\Asaas\AsaasClienteService;
use App\Services\Financeiro\Asaas\AsaasCobrancaService;
use App\Services\Financeiro\Asaas\AsaasConfigService;
use App\Services\Financeiro\Asaas\AsaasException;
use App\Services\Financeiro\Asaas\AsaasRotinasService;
use App\Services\Financeiro\Asaas\AsaasSincronizacaoService;
use App\Services\Financeiro\FinanceiroConfigService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central Asaas (Financeiro): pagadores sincronizados com o Asaas e,
 * nas próximas fases, emissão e acompanhamento das cobranças.
 */
class AsaasController extends Controller
{
    public function __construct(
        private AsaasClienteService $clientes,
        private AsaasCobrancaService $cobrancas,
        private AsaasConfigService $config,
        private FinanceiroConfigService $financeiro,
        private AsaasSincronizacaoService $sincronizacao,
        private AsaasRotinasService $rotinas,
        private LogSistemaService $log,
    ) {
    }

    /** Pagadores com parcela em aberto e a situação de cada um no Asaas. */
    public function clientes(): JsonResponse
    {
        $lista = $this->clientes->pagadores();
        $contagem = array_count_values(array_column($lista, 'situacao'));

        return response()->json(['success' => true, 'data' => [
            'ambiente'  => $this->config->ambiente(),
            'tem_chave' => $this->config->apiKey() !== null,
            'resumo'    => [
                'total'        => count($lista),
                'sincronizado' => $contagem['sincronizado'] ?? 0,
                'pronto'       => $contagem['pronto'] ?? 0,
                'pendente'     => $contagem['pendente'] ?? 0,
            ],
            'pagadores' => $lista,
        ]]);
    }

    /**
     * Envia ao Asaas os responsáveis escolhidos (ou, sem lista, todos os
     * prontos + já sincronizados, para atualizar os dados).
     */
    public function sincronizarClientes(Request $request): JsonResponse
    {
        if (!$this->config->apiKey()) {
            return response()->json(['error' => 'Cadastre a chave da API do Asaas em Parametrizações → Financeiro.'], 422);
        }

        $ids = (array) $request->input('responsavel_ids', []);
        if (!$ids) {
            $ids = collect($this->clientes->pagadores())
                ->whereIn('situacao', ['pronto', 'sincronizado'])
                ->pluck('responsavel_id')->all();
        }
        if (count($ids) > 300) {
            return response()->json(['error' => 'Envie no máximo 300 responsáveis por vez.'], 422);
        }

        $resultado = $this->clientes->sincronizarVarios($ids);
        $ok = count(array_filter($resultado, fn ($r) => $r['ok']));

        $jwtUser = $request->attributes->get('max_user');
        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'ASAAS_SINCRONIZAR_CLIENTES',
            '/api/financeiro/asaas/clientes/sincronizar', 'POST',
            "Clientes enviados ao Asaas ({$this->config->ambiente()}): $ok de " . count($resultado), 200,
            ['falhas' => array_values(array_filter($resultado, fn ($r) => !$r['ok']))]
        );

        return response()->json(['success' => true, 'data' => [
            'enviados'  => $ok,
            'falhas'    => count($resultado) - $ok,
            'resultado' => $resultado,
        ]]);
    }

    // ── Cobranças ────────────────────────────────────────────────────────

    public function resumoCobrancas(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->cobrancas->resumo($this->dias($request)) + [
            'ambiente'           => $this->config->ambiente(),
            'tem_chave'          => $this->config->apiKey() !== null,
            'dias'               => $this->dias($request),
            'emissao_automatica' => $this->financeiro->todas()['asaas_emissao_automatica'],
            'rotinas'            => $this->rotinas->ultimas(),
        ]]);
    }

    /** Parcelas que podem virar cobrança (vencem até hoje + N dias, sem cobrança ativa). */
    public function candidatas(Request $request): JsonResponse
    {
        $dias = $this->dias($request);
        return response()->json(['success' => true, 'data' => [
            'dias'       => $dias,
            'candidatas' => $this->cobrancas->candidatas($dias),
        ]]);
    }

    /**
     * Emite as cobranças das parcelas escolhidas. Cada uma faz 3–4 chamadas ao
     * Asaas, então o front manda em blocos pequenos (máx. 25) para não estourar
     * o tempo limite do servidor.
     */
    public function emitir(Request $request): JsonResponse
    {
        if (!$this->config->apiKey()) {
            return response()->json(['error' => 'Cadastre a chave da API do Asaas em Parametrizações → Financeiro.'], 422);
        }
        $ids = array_filter(array_map('intval', (array) $request->input('parcela_ids', [])));
        if (!$ids) {
            return response()->json(['error' => 'Escolha ao menos uma parcela.'], 422);
        }
        if (count($ids) > 25) {
            return response()->json(['error' => 'Envie no máximo 25 parcelas por vez.'], 422);
        }
        $novoVencimento = $request->input('novo_vencimento');
        if ($novoVencimento && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $novoVencimento)) {
            return response()->json(['error' => 'Novo vencimento inválido.'], 422);
        }

        $jwtUser = $request->attributes->get('max_user');
        $resultado = $this->cobrancas->emitirVarias($ids, $novoVencimento ?: null, (bool) $request->input('incluir_encargos', true), (int) ($jwtUser->id ?? 0));
        $ok = count(array_filter($resultado, fn ($r) => $r['ok']));

        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'ASAAS_EMITIR_COBRANCAS',
            '/api/financeiro/asaas/cobrancas/emitir', 'POST',
            "Cobranças emitidas no Asaas ({$this->config->ambiente()}): $ok de " . count($resultado), 200,
            ['resultado' => $resultado]
        );

        return response()->json(['success' => true, 'data' => [
            'emitidas'  => $ok,
            'falhas'    => count($resultado) - $ok,
            'resultado' => $resultado,
        ]]);
    }

    public function cobrancas(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->cobrancas->listar($request->query('situacao'), $request->query('busca'))]);
    }

    public function cancelarCobranca(Request $request, int $id): JsonResponse
    {
        try {
            $this->cobrancas->cancelar($id);
        } catch (AsaasException | \DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $jwtUser = $request->attributes->get('max_user');
        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'ASAAS_CANCELAR_COBRANCA',
            "/api/financeiro/asaas/cobrancas/$id/cancelar", 'POST', "Cobrança Asaas #$id cancelada", 200, ['cobranca_id' => $id]
        );

        return response()->json(['success' => true, 'message' => 'Cobrança cancelada no Asaas.']);
    }

    /** "Sincronizar com o Asaas": pergunta o status das cobranças em aberto e dá as baixas. */
    public function sincronizar(Request $request): JsonResponse
    {
        if (!$this->config->apiKey()) {
            return response()->json(['error' => 'Cadastre a chave da API do Asaas em Parametrizações → Financeiro.'], 422);
        }
        $res = $this->sincronizacao->sincronizar();
        $this->rotinas->registrar('sincronizacao', "{$res['consultadas']} consultada(s), {$res['baixas']} baixa(s), {$res['alteradas']} alterada(s)"
            . ($res['erros'] ? ", {$res['erros']} erro(s)" : '') . ' — pelo botão', $res['detalhes']);

        $jwtUser = $request->attributes->get('max_user');
        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'ASAAS_SINCRONIZAR',
            '/api/financeiro/asaas/sincronizar', 'POST',
            "Sincronização Asaas: {$res['consultadas']} consultadas, {$res['baixas']} baixas, {$res['alteradas']} alteradas, {$res['erros']} erros", 200,
            ['detalhes' => $res['detalhes']]
        );

        return response()->json(['success' => true, 'data' => $res]);
    }

    /** Últimos avisos (webhooks) recebidos do Asaas — para conferir se estão chegando. */
    public function eventos(): JsonResponse
    {
        $eventos = DB::table('asaas_webhook_eventos as e')
            ->leftJoin('asaas_cobrancas as c', 'c.payment_id', '=', 'e.payment_id')
            ->orderByDesc('e.id')->limit(100)
            ->get(['e.id', 'e.evento', 'e.payment_id', 'e.recebido_em', 'e.resultado', 'e.mensagem', 'c.parcela_id']);

        return response()->json(['success' => true, 'data' => $eventos]);
    }

    private function dias(Request $request): int
    {
        $padrao = $this->financeiro->todas()['remessa_dias_antecedencia'];
        return max(0, min(90, (int) $request->query('dias', $padrao)));
    }
}
