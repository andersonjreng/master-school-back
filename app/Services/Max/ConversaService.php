<?php

namespace App\Services\Max;

use App\Models\MaxConversa;
use App\Models\MaxMensagem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Envolve o MaxAgentService (que nao muda) pra persistir o historico de conversas
 * no banco, ao inves do Angular reenviar o array de mensagens a cada requisicao.
 */
class ConversaService
{
    public function __construct(private MaxAgentService $agent)
    {
    }

    /**
     * @return array{status: int, body: array}
     */
    public function responderNaConversa(object $jwtUser, string $pergunta, ?int $conversaId, string $token): array
    {
        $userId = (int) ($jwtUser->id ?? 0);

        $conversa = null;
        if ($conversaId !== null) {
            $conversa = MaxConversa::where('usuario_id', $userId)->findOrFail($conversaId);
        }

        $historico = $conversa
            ? $this->carregarHistorico($conversa)
            : [];

        $resultado = $this->agent->responder($pergunta, $historico, $token, $jwtUser);

        if (!isset($resultado['body']['resposta'])) {
            // Erro ou limite atingido: nao persiste nada, a troca "nao aconteceu"
            // do ponto de vista do historico salvo.
            return $resultado;
        }

        $tituloNovo = null;

        DB::transaction(function () use (&$conversa, &$tituloNovo, $userId, $pergunta, $resultado) {
            if (!$conversa) {
                $tituloNovo = Str::limit(trim($pergunta), 60);
                $conversa = MaxConversa::create([
                    'usuario_id' => $userId,
                    'titulo'     => $tituloNovo,
                ]);
            }

            $conversa->mensagens()->create([
                'role'    => 'user',
                'content' => $pergunta,
            ]);

            $conversa->mensagens()->create([
                'role'           => 'assistant',
                'content'        => $resultado['body']['resposta'],
                'tokens_entrada' => $resultado['body']['uso']['tokens_entrada'] ?? null,
                'tokens_saida'   => $resultado['body']['uso']['tokens_saida'] ?? null,
            ]);

            $conversa->touch();
        });

        $resultado['body']['conversa_id'] = $conversa->id;
        if ($tituloNovo !== null) {
            $resultado['body']['titulo'] = $tituloNovo;
        }

        return $resultado;
    }

    public function listarDoUsuario(int $userId): Collection
    {
        return MaxConversa::where('usuario_id', $userId)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'titulo', 'updated_at']);
    }

    public function obterComMensagens(int $userId, int $conversaId): MaxConversa
    {
        return MaxConversa::where('usuario_id', $userId)
            ->with(['mensagens' => fn ($q) => $q->orderBy('created_at')])
            ->findOrFail($conversaId);
    }

    public function excluir(int $userId, int $conversaId): void
    {
        MaxConversa::where('usuario_id', $userId)->findOrFail($conversaId)->delete();
    }

    private function carregarHistorico(MaxConversa $conversa): array
    {
        $limite = (int) config('max.historico_max_mensagens');

        return $conversa->mensagens()
            ->orderByDesc('created_at')
            ->limit($limite)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();
    }
}
