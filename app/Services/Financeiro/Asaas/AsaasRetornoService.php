<?php

namespace App\Services\Financeiro\Asaas;

use App\Services\Financeiro\FinanceiroHelperService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica no sistema o que aconteceu com uma cobrança no Asaas.
 *
 * Duas portas de entrada chegam aqui com o mesmo objeto "payment":
 *  - o webhook (Asaas avisa na hora — AsaasWebhookController);
 *  - a sincronização (o sistema pergunta — botão na Central e comando agendado).
 *
 * Tudo é idempotente: processar o mesmo pagamento duas vezes (webhook
 * repetido, CONFIRMED e depois RECEIVED, webhook + sincronização) só dá
 * baixa uma vez. A linha da cobrança fica travada (FOR UPDATE) durante o
 * processamento para dois avisos simultâneos não darem baixa em dobro.
 */
class AsaasRetornoService
{
    /** Status do Asaas que significam "o dinheiro foi pago". */
    public const PAGOS = ['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'];

    private const ESTORNOS = ['REFUNDED', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL'];

    public function __construct(
        private FinanceiroHelperService $helper,
        private AsaasClient $api,
    ) {
    }

    /**
     * @return array{resultado: 'aplicado'|'ignorado', mensagem: string, baixa: bool}
     */
    public function processar(array $payment, string $origem, ?string $evento = null): array
    {
        $paymentId = $payment['id'] ?? null;
        if (!$paymentId) {
            return $this->r('ignorado', 'Evento sem cobrança.');
        }

        return DB::transaction(function () use ($payment, $paymentId, $origem, $evento) {
            $c = DB::table('asaas_cobrancas')->where('payment_id', $paymentId)->lockForUpdate()->first();
            if (!$c) {
                return $this->r('ignorado', "Cobrança $paymentId não foi emitida por este sistema.");
            }

            $status = (string) ($payment['status'] ?? $c->status);
            $deletada = !empty($payment['deleted']) || $evento === 'PAYMENT_DELETED';
            $atualizar = ['status' => $deletada ? 'DELETED' : $status, 'ultimo_evento' => $evento ?? "sincronizacao:$status"];

            // Valor e vencimento podem mudar no painel do Asaas (PAYMENT_UPDATED).
            if (isset($payment['value']) && !in_array($status, self::PAGOS, true)) {
                $atualizar['valor'] = $payment['value'];
            }
            if (!empty($payment['dueDate'])) {
                $atualizar['vencimento'] = $payment['dueDate'];
            }
            if ($evento === 'PAYMENT_UPDATED') {
                $linha = $this->linhaDigitavel($paymentId);
                if ($linha) {
                    $atualizar['linha_digitavel'] = $linha;
                }
            }

            // ── Cancelada no Asaas ──
            if ($deletada) {
                DB::table('asaas_cobrancas')->where('id', $c->id)->update($atualizar + ['ativa' => 0, 'cancelado_em' => $c->cancelado_em ?? now()]);
                return $this->r('aplicado', 'Cobrança cancelada no Asaas — a parcela volta a ficar disponível para emissão.');
            }

            // ── Paga ──
            if (in_array($status, self::PAGOS, true)) {
                return $this->registrarPagamento($c, $payment, $atualizar, $origem);
            }

            // ── Estorno / chargeback: não desfaz a baixa sozinho, avisa ──
            if (in_array($status, self::ESTORNOS, true)) {
                DB::table('asaas_cobrancas')->where('id', $c->id)->update($atualizar);
                Log::warning('Asaas: estorno/chargeback em cobrança', ['payment' => $paymentId, 'status' => $status, 'parcela' => $c->parcela_id]);
                return $this->r('aplicado', "Cobrança com $status — confira a parcela #{$c->parcela_id} (a baixa não é desfeita automaticamente).");
            }

            // ── Pendente / vencida / outros: só espelha o status ──
            DB::table('asaas_cobrancas')->where('id', $c->id)->update($atualizar);
            return $this->r('aplicado', "Status atualizado: $status.");
        });
    }

    private function registrarPagamento(object $c, array $payment, array $atualizar, string $origem): array
    {
        $valorPago = (float) ($payment['value'] ?? $c->valor);
        $data = $payment['paymentDate'] ?? $payment['clientPaymentDate'] ?? $payment['confirmedDate'] ?? Carbon::today()->toDateString();
        $atualizar += ['valor_pago' => $valorPago, 'data_pagamento' => $data];

        // Já demos baixa por esta cobrança (CONFIRMED e depois RECEIVED, ou aviso repetido).
        if ($c->baixa_id) {
            DB::table('asaas_cobrancas')->where('id', $c->id)->update($atualizar);
            return $this->r('ignorado', 'Pagamento já registrado anteriormente.');
        }

        $parcela = DB::table('parcelas')->where('id', $c->parcela_id)->lockForUpdate()->first(['id', 'status']);
        if (!$parcela || $parcela->status === 'pago') {
            // Ex.: baixa manual no balcão antes do aviso, ou "recebido em dinheiro" que nós mesmos marcamos.
            DB::table('asaas_cobrancas')->where('id', $c->id)->update($atualizar);
            return $this->r('ignorado', "Parcela #{$c->parcela_id} já estava paga no sistema.");
        }
        if (in_array($parcela->status, ['cancelado', 'negociado'], true)) {
            DB::table('asaas_cobrancas')->where('id', $c->id)->update($atualizar);
            Log::warning('Asaas: pagamento de parcela cancelada/renegociada', ['payment' => $c->payment_id, 'parcela' => $c->parcela_id]);
            return $this->r('ignorado', "Pagamento recebido para a parcela #{$c->parcela_id}, que está {$parcela->status} — verifique.");
        }

        $forma = match ($payment['billingType'] ?? '') {
            'PIX' => 'pix',
            'CREDIT_CARD' => 'cartao_credito',
            'DEBIT_CARD' => 'cartao_debito',
            default => 'boleto',
        };
        $comoPagou = $forma === 'pix' ? 'Pix' : 'boleto';
        $via = $origem === 'webhook' ? 'aviso do Asaas' : 'sincronização com o Asaas';

        // usuarioId 0 = baixa automática (o recibo aparece como "Asaas (automático)").
        $baixa = $this->helper->aplicarBaixaParcela(
            (int) $c->parcela_id, $valorPago, $data, $forma,
            "Baixa automática — pago por $comoPagou no Asaas ({$c->payment_id}), registrada por $via.",
            0,
        );

        DB::table('asaas_cobrancas')->where('id', $c->id)->update($atualizar + ['baixa_id' => $baixa['baixa_id']]);

        return $this->r('aplicado', "Baixa da parcela #{$c->parcela_id}: R$ " . number_format($valorPago, 2, ',', '.') . " por $comoPagou. Recibo {$baixa['numero_recibo']}.", true);
    }

    private function linhaDigitavel(string $paymentId): ?string
    {
        try {
            return $this->api->get("/payments/$paymentId/identificationField")['identificationField'] ?? null;
        } catch (AsaasException) {
            return null;
        }
    }

    private function r(string $resultado, string $mensagem, bool $baixa = false): array
    {
        return ['resultado' => $resultado, 'mensagem' => $mensagem, 'baixa' => $baixa];
    }
}
