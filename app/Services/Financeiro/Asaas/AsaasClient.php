<?php

namespace App\Services\Financeiro\Asaas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Único ponto do sistema que conversa com a API do Asaas (v3).
 *
 * - URL base depende do ambiente: sandbox (testes, dinheiro de mentira) ou produção.
 * - Autenticação: header `access_token` com a chave da API da escola.
 * - User-Agent é obrigatório para contas criadas a partir de jun/2024.
 * - Erros do Asaas vêm como {"errors":[{"code":..,"description":..}]} e viram AsaasException.
 */
class AsaasClient
{
    private const URLS = [
        'sandbox'  => 'https://api-sandbox.asaas.com/v3',
        'producao' => 'https://api.asaas.com/v3',
    ];

    public function __construct(private AsaasConfigService $config)
    {
    }

    public function get(string $path, array $query = []): array
    {
        return $this->enviar('GET', $path, $query);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->enviar('POST', $path, $body);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->enviar('PUT', $path, $body);
    }

    public function delete(string $path): array
    {
        return $this->enviar('DELETE', $path);
    }

    /** Dados da conta dona da chave — usado pelo "Testar conexão". */
    public function dadosDaConta(): array
    {
        $r = $this->get('/myAccount/commercialInfo/');
        return [
            'nome'      => $r['companyName'] ?? $r['name'] ?? null,
            'documento' => $r['cpfCnpj'] ?? null,
            'email'     => $r['email'] ?? null,
            'status'    => $r['status'] ?? null,
        ];
    }

    private function enviar(string $metodo, string $path, array $dados = []): array
    {
        $chave = $this->config->apiKey();
        if (!$chave) {
            throw new AsaasException('A chave da API do Asaas ainda não foi cadastrada (Parametrizações → Financeiro).');
        }
        $ambiente = $this->config->ambiente();

        try {
            $resposta = $this->http($chave, $ambiente)->send($metodo, ltrim($path, '/'), $metodo === 'GET'
                ? ['query' => $dados]
                : ($dados ? ['json' => $dados] : []));
        } catch (ConnectionException $e) {
            Log::warning('Asaas: falha de conexão', ['metodo' => $metodo, 'path' => $path, 'erro' => $e->getMessage()]);
            throw new AsaasException('Não foi possível falar com o Asaas agora. Tente de novo em instantes.');
        }

        if ($resposta->successful()) {
            return $resposta->json() ?? [];
        }

        $erros = $resposta->json('errors') ?? [];
        Log::warning('Asaas: erro na API', ['metodo' => $metodo, 'path' => $path, 'status' => $resposta->status(), 'erros' => $erros]);

        $mensagem = match (true) {
            $resposta->status() === 401 => 'O Asaas recusou a chave da API. Confira se ela está certa e se é do ambiente escolhido (' . ($ambiente === 'sandbox' ? 'sandbox' : 'produção') . ').',
            $resposta->status() === 404 => 'Registro não encontrado no Asaas.',
            !empty($erros)              => implode(' ', array_map(fn ($e) => $e['description'] ?? $e['code'] ?? '', $erros)),
            default                     => "O Asaas respondeu com erro ({$resposta->status()}).",
        };
        throw new AsaasException($mensagem, $resposta->status(), $erros);
    }

    private function http(string $chave, string $ambiente): PendingRequest
    {
        return Http::baseUrl(self::URLS[$ambiente])
            ->withHeaders([
                'access_token' => $chave,
                'User-Agent'   => 'MasterSchool/1.0',
            ])
            ->acceptJson()
            ->timeout(20);
    }
}
