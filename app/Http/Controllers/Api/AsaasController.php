<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\Asaas\AsaasClienteService;
use App\Services\Financeiro\Asaas\AsaasConfigService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central Asaas (Financeiro): pagadores sincronizados com o Asaas e,
 * nas próximas fases, emissão e acompanhamento das cobranças.
 */
class AsaasController extends Controller
{
    public function __construct(
        private AsaasClienteService $clientes,
        private AsaasConfigService $config,
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
}
