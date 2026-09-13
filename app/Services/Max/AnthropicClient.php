<?php

namespace App\Services\Max;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Equivalente a chamarClaude() em api/agent/agent.php.
 */
class AnthropicClient
{
    public function enviarMensagens(array $messages, array $tools, string $systemPrompt): array
    {
        $tentativas = 0;

        do {
            $resposta = $this->chamar($messages, $tools, $systemPrompt);
            $tipoErro = $resposta['error']['type'] ?? '';

            if ($tipoErro === 'overloaded_error') {
                $tentativas++;
                Log::channel('max')->warning('Anthropic overloaded - aguardando retry', [
                    'tentativa' => $tentativas,
                ]);
                sleep(3);
            }
        } while ($tipoErro === 'overloaded_error' && $tentativas < 3);

        $resposta['_tentativas_overload'] = $tentativas;

        return $resposta;
    }

    private function chamar(array $messages, array $tools, string $systemPrompt): array
    {
        $payload = [
            'model'      => config('max.anthropic_model'),
            'max_tokens' => 1024,
            'system'     => $systemPrompt,
            'tools'      => $tools,
            'messages'   => $messages,
        ];

        $response = Http::withHeaders([
                'x-api-key'         => config('max.anthropic_api_key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', $payload);

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : ['error' => ['message' => 'Resposta invalida da API Claude']];
    }
}
