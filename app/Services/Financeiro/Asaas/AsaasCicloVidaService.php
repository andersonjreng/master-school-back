<?php

namespace App\Services\Financeiro\Asaas;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mantém o Asaas coerente com o que acontece com a parcela no sistema.
 *
 * Toda tela que mexe numa parcela com cobrança Asaas ativa passa por aqui:
 *  - baixa manual (pagou no balcão) → "recebido em dinheiro" no Asaas, para o
 *    boleto/Pix deixarem de ser pagáveis (sem isso a família pagaria duas vezes);
 *  - edição de valor/vencimento → atualiza a cobrança;
 *  - renegociação, cancelamento de matrícula → cancela a cobrança.
 *
 * Quem chama faz isso DENTRO da transação da própria ação: se o Asaas recusar,
 * a exceção desfaz a ação no sistema e a tela mostra o motivo.
 *
 * Só olha cobranças do ambiente atual (as do sandbox não existem na conta de produção).
 */
class AsaasCicloVidaService
{
    private const ABERTAS = ['PENDING', 'OVERDUE'];

    public function __construct(
        private AsaasClient $api,
        private AsaasConfigService $config,
    ) {
    }

    /** Cobrança ativa da parcela no ambiente atual (ou null). */
    public function cobrancaAtiva(int $parcelaId): ?object
    {
        if (!DB::getSchemaBuilder()->hasTable('asaas_cobrancas')) {
            return null; // banco de escola sem a migration do Asaas
        }
        return DB::table('asaas_cobrancas')
            ->where('parcela_id', $parcelaId)
            ->where('ativa', 1)
            ->where('ambiente', $this->config->ambiente())
            ->first();
    }

    /**
     * Antes de qualquer ação: se o Asaas já recebeu e o sistema ainda não deu
     * baixa, mexer na parcela agora geraria cobrança em dobro ou dinheiro sem
     * parcela. Pede para sincronizar.
     *
     * @throws \DomainException
     */
    public function exigirSemPagamentoPendente(int $parcelaId): void
    {
        $c = $this->cobrancaAtiva($parcelaId);
        if ($c && in_array($c->status, AsaasRetornoService::PAGOS, true) && !$c->baixa_id) {
            throw new \DomainException('Esta parcela já foi paga pelo Asaas e o sistema ainda não registrou. Use "Sincronizar com o Asaas" na Central Asaas antes.');
        }
    }

    /**
     * Baixa manual de parcela com cobrança aberta: informa ao Asaas que foi
     * recebida por fora. Chamar DEPOIS de gravar a baixa, na mesma transação.
     *
     * @throws AsaasException|\DomainException
     */
    public function aposBaixaManual(int $parcelaId, int $baixaId, float $valor, string $data): void
    {
        $c = $this->cobrancaAtiva($parcelaId);
        if (!$c || !in_array($c->status, self::ABERTAS, true)) {
            return;
        }
        // O Asaas não aceita data de recebimento no futuro.
        $dataAsaas = min($data, Carbon::today()->toDateString());
        $this->api->post("/payments/{$c->payment_id}/receiveInCash", [
            'paymentDate'    => $dataAsaas,
            'value'          => $valor,
            'notifyCustomer' => false,
        ]);
        DB::table('asaas_cobrancas')->where('id', $c->id)->update([
            'status' => 'RECEIVED_IN_CASH', 'baixa_id' => $baixaId, 'valor_pago' => $valor,
            'data_pagamento' => $data, 'ultimo_evento' => 'baixa_manual',
        ]);
    }

    /**
     * Edição de valor/vencimento: leva a mudança para a cobrança aberta.
     * Chamar ANTES de gravar (se o Asaas recusar, nada muda no sistema).
     *
     * @throws AsaasException|\DomainException
     */
    public function aoEditar(int $parcelaId, float $valorFinal, string $vencimento): void
    {
        $this->exigirSemPagamentoPendente($parcelaId);
        $c = $this->cobrancaAtiva($parcelaId);
        if (!$c || !in_array($c->status, self::ABERTAS, true)) {
            return;
        }
        if (round((float) $c->valor, 2) === round($valorFinal, 2) && $c->vencimento === $vencimento) {
            return;
        }
        if ($vencimento < Carbon::today()->toDateString()) {
            throw new \DomainException('Esta parcela tem boleto emitido no Asaas, que não aceita vencimento no passado. Escolha uma data a partir de hoje ou cancele a cobrança na Central Asaas antes.');
        }

        $p = $this->api->put("/payments/{$c->payment_id}", ['value' => $valorFinal, 'dueDate' => $vencimento]);

        $linha = null;
        try {
            $linha = $this->api->get("/payments/{$c->payment_id}/identificationField")['identificationField'] ?? null;
        } catch (AsaasException) {
            // segue com a anterior; o webhook PAYMENT_UPDATED também atualiza
        }
        DB::table('asaas_cobrancas')->where('id', $c->id)->update(array_filter([
            'valor'           => $p['value'] ?? $valorFinal,
            'vencimento'      => $p['dueDate'] ?? $vencimento,
            'status'          => $p['status'] ?? null,
            'linha_digitavel' => $linha,
            'ultimo_evento'   => 'edicao_parcela',
        ], fn ($v) => $v !== null));
    }

    /**
     * Renegociação / cancelamento: cancela no Asaas as cobranças abertas das
     * parcelas. Chamar ANTES de mudar as parcelas.
     *
     * @param int[] $parcelaIds
     * @return int quantas cobranças foram canceladas
     * @throws AsaasException|\DomainException
     */
    public function cancelarDasParcelas(array $parcelaIds): int
    {
        $canceladas = 0;
        foreach (array_unique($parcelaIds) as $id) {
            $this->exigirSemPagamentoPendente((int) $id);
            $c = $this->cobrancaAtiva((int) $id);
            if (!$c || !in_array($c->status, self::ABERTAS, true)) {
                continue;
            }
            try {
                $this->api->delete("/payments/{$c->payment_id}");
            } catch (AsaasException $e) {
                if ($e->httpStatus !== 404) {
                    throw $e;
                }
            }
            DB::table('asaas_cobrancas')->where('id', $c->id)->update([
                'status' => 'DELETED', 'ativa' => 0, 'cancelado_em' => now(), 'ultimo_evento' => 'cancelada_pelo_sistema',
            ]);
            $canceladas++;
        }
        return $canceladas;
    }
}
