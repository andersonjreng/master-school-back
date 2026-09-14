<?php

namespace App\Services\Financeiro;

use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/helpers/financeiro_helper.php.
 */
class FinanceiroHelperService
{
    /**
     * Lê multa/juros/carência de configuracoes_financeiras, com fallback para os
     * valores padrão caso a tabela ainda não tenha sido populada.
     */
    public function carregarConfiguracoes(): array
    {
        $config = [
            'multa_pct' => 2.00,
            'juros_dia' => 0.0333,
            'carencia' => 5,
        ];

        $rows = DB::table('configuracoes_financeiras')->get(['chave', 'valor']);
        foreach ($rows as $row) {
            match ($row->chave) {
                'multa_percentual' => $config['multa_pct'] = (float) $row->valor,
                'juros_diario_percentual' => $config['juros_dia'] = (float) $row->valor,
                'dias_carencia' => $config['carencia'] = (int) $row->valor,
                default => null,
            };
        }

        return $config;
    }

    /**
     * Calcula multa (única vez) + juros de mora (por dia) sobre uma parcela vencida,
     * respeitando os dias de carência. Fora da carência, retorna tudo zerado.
     * $dataReferencia é o dia até quando contar o atraso — "hoje" para exibir uma
     * parcela ainda em aberto, ou a data do pagamento ao registrar uma baixa
     * (para não recalcular o encargo com base na data em que a baixa foi digitada).
     */
    public function calcularEncargos(array $config, string $dataVencimento, float $valorFinal, ?string $dataReferencia = null): array
    {
        $dataReferencia = $dataReferencia ?? date('Y-m-d');
        $diasAtraso = max(0, (int) ((strtotime($dataReferencia) - strtotime($dataVencimento)) / 86400));
        $diasEfetivos = max(0, $diasAtraso - $config['carencia']);

        $multaValor = $diasEfetivos > 0 ? round($valorFinal * ($config['multa_pct'] / 100), 2) : 0.0;
        $jurosValor = $diasEfetivos > 0 ? round($valorFinal * ($config['juros_dia'] / 100) * $diasEfetivos, 2) : 0.0;
        $totalEncargos = round($multaValor + $jurosValor, 2);

        return [
            'dias_atraso' => $diasAtraso,
            'dias_efetivos' => $diasEfetivos,
            'multa_valor' => $multaValor,
            'juros_valor' => $jurosValor,
            'total_encargos' => $totalEncargos,
            'valor_com_encargos' => round($valorFinal + $totalEncargos, 2),
            'config_multa_pct' => $config['multa_pct'],
            'config_juros_dia' => $config['juros_dia'],
            'config_carencia' => $config['carencia'],
        ];
    }

    /**
     * Aplica a baixa de uma parcela: insere baixas_pagamento, atualiza a parcela
     * pra 'pago' e gera o recibo — tudo numa transação própria.
     *
     * @throws \Exception se a parcela não existe, já está paga, ou está cancelada/negociada.
     */
    public function aplicarBaixaParcela(
        int $parcelaId,
        float $valorPago,
        string $dataPagamento,
        string $formaPagamento,
        string $observacoes,
        int $usuarioId,
        ?int $boletoId = null,
        ?int $retornoId = null,
    ): array {
        $parcela = DB::table('parcelas')->where('id', $parcelaId)
            ->first(['id', 'aluno_id', 'status', 'valor_final', 'data_vencimento']);

        if (!$parcela) {
            throw new \Exception('Parcela não encontrada.');
        }
        if ($parcela->status === 'pago') {
            throw new \Exception('Parcela já está paga.');
        }
        if (in_array($parcela->status, ['cancelado', 'negociado'], true)) {
            throw new \Exception('Parcela cancelada ou negociada não pode receber baixa.');
        }

        $alunoId = (int) $parcela->aluno_id;

        $config = $this->carregarConfiguracoes();
        $encargos = $parcela->status === 'vencido'
            ? $this->calcularEncargos($config, $parcela->data_vencimento, (float) $parcela->valor_final, $dataPagamento)
            : ['multa_valor' => 0.0, 'juros_valor' => 0.0];
        $breakdown = $this->distribuirValorPago($valorPago, (float) $parcela->valor_final, $encargos['multa_valor'], $encargos['juros_valor']);

        return DB::transaction(function () use (
            $parcelaId, $boletoId, $retornoId, $valorPago, $breakdown, $dataPagamento,
            $formaPagamento, $observacoes, $usuarioId, $alunoId,
        ) {
            $baixaId = DB::table('baixas_pagamento')->insertGetId([
                'parcela_id' => $parcelaId,
                'boleto_id' => $boletoId,
                'retorno_id' => $retornoId,
                'valor_pago' => $valorPago,
                'valor_principal' => $breakdown['valor_principal'],
                'valor_multa' => $breakdown['valor_multa'],
                'valor_juros' => $breakdown['valor_juros'],
                'data_pagamento' => $dataPagamento,
                'forma_pagamento' => $formaPagamento,
                'observacoes' => $observacoes,
                'registrado_por' => $usuarioId,
            ]);

            DB::table('parcelas')->where('id', $parcelaId)->update([
                'status' => 'pago',
                'data_pagamento' => $dataPagamento,
                'forma_pagamento' => $formaPagamento,
                'valor_pago' => $valorPago,
            ]);

            $seq = str_pad((int) DB::table('recibos')->count() + 1, 5, '0', STR_PAD_LEFT);
            $numeroRecibo = 'REC-' . date('Y') . "-$seq";

            $responsavelNome = DB::table('responsaveis as r')
                ->join('aluno_responsavel as ar', 'r.id', '=', 'ar.responsavel_id')
                ->join('usuarios as u', 'r.usuario_id', '=', 'u.id')
                ->where('ar.aluno_id', $alunoId)
                ->value('u.nome_completo');

            $descRow = DB::table('parcelas as p')
                ->join('matriculas_financeiras as mf', 'p.matricula_financeira_id', '=', 'mf.id')
                ->join('planos_pagamento as pp', 'mf.plano_id', '=', 'pp.id')
                ->where('p.id', $parcelaId)
                ->first(['pp.nome as plano_nome', 'p.numero_parcela', 'pp.numero_parcelas', 'mf.ano_letivo']);

            $descricaoRecibo = $descRow
                ? "Parcela {$descRow->numero_parcela}/{$descRow->numero_parcelas} — {$descRow->plano_nome} ({$descRow->ano_letivo})"
                : "Parcela #{$parcelaId}";

            $formaLabel = str_replace(
                ['cartao_debito', 'cartao_credito'],
                ['Cartão Débito', 'Cartão Crédito'],
                str_replace(['dinheiro', 'pix', 'transferencia', 'boleto'], ['Dinheiro', 'Pix', 'Transferência', 'Boleto'], $formaPagamento)
            );

            $reciboId = DB::table('recibos')->insertGetId([
                'numero_recibo' => $numeroRecibo,
                'baixa_id' => $baixaId,
                'parcela_id' => $parcelaId,
                'aluno_id' => $alunoId,
                'responsavel_nome' => $responsavelNome,
                'valor_recibo' => $valorPago,
                'data_recibo' => $dataPagamento,
                'forma_pagamento' => $formaLabel,
                'descricao' => $descricaoRecibo,
                'criado_por' => $usuarioId,
            ]);

            return ['baixa_id' => $baixaId, 'recibo_id' => $reciboId, 'numero_recibo' => $numeroRecibo];
        });
    }

    /**
     * Distribui o valor efetivamente pago entre principal, multa e juros, na ordem
     * principal > multa > juros. Garante que a soma das 3 partes seja sempre igual
     * a $valorPago — se o valor pago for menor que o esperado (ex.: encargo
     * perdoado no balcão), o "furo" cai sobre a última parcela (juros); se for
     * maior, o excedente é somado ao principal.
     */
    private function distribuirValorPago(float $valorPago, float $valorPrincipalDevido, float $multaCalculada, float $jurosCalculada): array
    {
        $restante = round($valorPago, 2);

        $valorPrincipal = min($restante, round($valorPrincipalDevido, 2));
        $restante = round($restante - $valorPrincipal, 2);

        $valorMulta = min($restante, round($multaCalculada, 2));
        $restante = round($restante - $valorMulta, 2);

        $valorJuros = min($restante, round($jurosCalculada, 2));
        $restante = round($restante - $valorJuros, 2);

        $valorPrincipal = round($valorPrincipal + $restante, 2);

        return [
            'valor_principal' => $valorPrincipal,
            'valor_multa' => $valorMulta,
            'valor_juros' => $valorJuros,
        ];
    }
}
