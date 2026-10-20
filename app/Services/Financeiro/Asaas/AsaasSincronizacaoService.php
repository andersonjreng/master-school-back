<?php

namespace App\Services\Financeiro\Asaas;

use Illuminate\Support\Facades\DB;

/**
 * Sincronização: em vez de esperar o Asaas avisar (webhook), o sistema
 * pergunta o status de cada cobrança ainda não resolvida.
 *
 * É o plano B do webhook (aviso perdido, fila pausada, servidor fora do ar)
 * e o jeito de testar a baixa automática no computador local, que o Asaas
 * não consegue chamar.
 */
class AsaasSincronizacaoService
{
    /** Status em que a cobrança ainda pode mudar do lado do Asaas. */
    private const EM_ANDAMENTO = ['PENDING', 'OVERDUE', 'CONFIRMED', 'AWAITING_RISK_ANALYSIS'];

    public function __construct(
        private AsaasClient $api,
        private AsaasConfigService $config,
        private AsaasRetornoService $retorno,
    ) {
    }

    /**
     * @return array{consultadas: int, baixas: int, alteradas: int, erros: int, detalhes: array}
     */
    public function sincronizar(int $limite = 200): array
    {
        $cobrancas = DB::table('asaas_cobrancas')
            ->where('ambiente', $this->config->ambiente())
            ->where('ativa', 1)
            ->where(fn ($q) => $q->whereIn('status', self::EM_ANDAMENTO)
                // pagas sem baixa registrada (ex.: falha no meio do processamento)
                ->orWhere(fn ($q2) => $q2->whereIn('status', AsaasRetornoService::PAGOS)->whereNull('baixa_id')))
            ->orderBy('atualizado_em')
            ->limit($limite)
            ->get(['id', 'payment_id', 'status', 'parcela_id']);

        $res = ['consultadas' => 0, 'baixas' => 0, 'alteradas' => 0, 'erros' => 0, 'detalhes' => []];

        foreach ($cobrancas as $c) {
            $res['consultadas']++;
            try {
                $payment = $this->api->get("/payments/{$c->payment_id}");
            } catch (AsaasException $e) {
                if ($e->httpStatus === 404) {
                    $payment = ['id' => $c->payment_id, 'deleted' => true, 'status' => 'DELETED'];
                } else {
                    $res['erros']++;
                    $res['detalhes'][] = ['parcela_id' => $c->parcela_id, 'mensagem' => $e->getMessage()];
                    continue;
                }
            }

            $novoStatus = !empty($payment['deleted']) ? 'DELETED' : ($payment['status'] ?? $c->status);
            if ($novoStatus === $c->status && !in_array($novoStatus, AsaasRetornoService::PAGOS, true)) {
                continue; // nada mudou
            }

            try {
                $r = $this->retorno->processar($payment, 'sincronizacao', !empty($payment['deleted']) ? 'PAYMENT_DELETED' : null);
            } catch (\Throwable $e) {
                $res['erros']++;
                $res['detalhes'][] = ['parcela_id' => $c->parcela_id, 'mensagem' => $e->getMessage()];
                continue;
            }
            if ($r['resultado'] === 'aplicado') {
                $r['baixa'] ? $res['baixas']++ : $res['alteradas']++;
                $res['detalhes'][] = ['parcela_id' => $c->parcela_id, 'mensagem' => $r['mensagem'], 'baixa' => $r['baixa']];
            }
        }

        return $res;
    }
}
