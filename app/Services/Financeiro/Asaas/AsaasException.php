<?php

namespace App\Services\Financeiro\Asaas;

/**
 * Erro devolvido pela API do Asaas (ou falha de comunicação com ela).
 * A mensagem já vem em português, pronta para mostrar na tela.
 */
class AsaasException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly array $erros = [])
    {
        parent::__construct($message);
    }
}
