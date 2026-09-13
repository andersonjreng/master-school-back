<?php

namespace App\Services\Max;

use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP para a API PHP legada (ainda em producao). Equivalente a chamarApi()
 * em api/agent/agent.php - as "tools" do Max nao acessam o banco diretamente, elas
 * repassam o token do usuario logado para os endpoints REST ja existentes no servidor.
 */
class LegacyApiClient
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('max.legacy_api_base_url'), '/');
    }

    public function get(string $path, array $query, string $bearerToken): array
    {
        $request = Http::timeout(30);

        if ($bearerToken !== '') {
            $request = $request->withToken($bearerToken);
        }

        $response = $request->get($this->baseUrl . $path, $query);
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : ['raw' => $response->body()];
    }
}
