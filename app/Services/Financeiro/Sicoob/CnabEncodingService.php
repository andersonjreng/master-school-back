<?php

namespace App\Services\Financeiro\Sicoob;

/**
 * Equivalente a api/helpers/cnab_helper.php.
 *
 * A biblioteca openboleto/opencnabphp espera texto em ISO-8859-1 (Latin-1) —
 * é o encoding real usado pelos bancos brasileiros em CNAB, confirmado
 * batendo com o arquivo de retorno real do Sicoob que a escola passou. Nosso
 * banco guarda tudo em UTF-8 (utf8mb4), então todo campo de texto precisa
 * passar por aqui antes de ir pra biblioteca — senão um acento (ex: "Ç" de
 * "COBRANÇA") vira 2 bytes em vez de 1 e desalinha a linha inteira.
 */
class CnabEncodingService
{
    public function paraLatin1(?string $texto): string
    {
        if ($texto === null || $texto === '') {
            return '';
        }
        $convertido = @mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8');

        return $convertido !== false ? $convertido : $texto;
    }

    /** Aplica paraLatin1() em todo valor string de um array associativo (não recursivo). */
    public function arrayParaLatin1(array $dados): array
    {
        foreach ($dados as $chave => $valor) {
            if (is_string($valor)) {
                $dados[$chave] = $this->paraLatin1($valor);
            }
        }

        return $dados;
    }

    /** Remove tudo que não é dígito — usado pra CPF/CNPJ/CEP antes de mandar pro CNAB. */
    public function apenasDigitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }
}
