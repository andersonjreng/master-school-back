<?php

namespace App\Services\Financeiro\Sicoob;

/**
 * Códigos de ocorrência ("comando/movimento") do retorno CNAB 400 do Sicoob,
 * traduzidos e agrupados pelo efeito no sistema:
 *
 * - liquidacao: título pago → baixa automática da parcela (gera recibo).
 * - registro:   banco confirmou o boleto → passa a ser pagável.
 * - rejeicao:   banco recusou o título → boleto precisa ser corrigido/reemitido.
 * - baixa:      título baixado no banco sem pagamento → boleto deixa de valer.
 * - alteracao:  vencimento/dados alterados no banco.
 * - informativo: o resto (só registrado no histórico).
 */
class SicoobOcorrencias
{
    private const MAPA = [
        '02' => ['Entrada confirmada (boleto registrado no banco)', 'registro'],
        '03' => ['Entrada rejeitada pelo banco', 'rejeicao'],
        '05' => ['Liquidação sem registro', 'liquidacao'],
        '06' => ['Liquidação (boleto pago)', 'liquidacao'],
        '09' => ['Baixa automática do título', 'baixa'],
        '10' => ['Baixa solicitada pelo cedente', 'baixa'],
        '11' => ['Título em carteira (em aberto)', 'informativo'],
        '12' => ['Abatimento concedido', 'alteracao'],
        '13' => ['Abatimento cancelado', 'alteracao'],
        '14' => ['Vencimento alterado', 'alteracao'],
        '15' => ['Liquidação em cartório', 'liquidacao'],
        '23' => ['Encaminhado a protesto', 'informativo'],
        '24' => ['Instrução rejeitada pelo banco', 'rejeicao'],
        '27' => ['Alteração de dados confirmada', 'alteracao'],
        '30' => ['Alteração de dados rejeitada', 'rejeicao'],
    ];

    /** @return array{0: string, 1: string} [descrição, categoria] */
    public static function descrever(string $codigo): array
    {
        $codigo = str_pad(ltrim($codigo, '0') ?: '0', 2, '0', STR_PAD_LEFT);
        return self::MAPA[$codigo] ?? ["Ocorrência $codigo", 'informativo'];
    }

    public static function normalizar(int|string $codigo): string
    {
        return str_pad((string) (int) $codigo, 2, '0', STR_PAD_LEFT);
    }
}
