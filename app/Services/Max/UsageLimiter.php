<?php

namespace App\Services\Max;

use Illuminate\Support\Facades\DB;

/**
 * Equivalente a verificarLimite() e registrarUso() em api/agent/agent.php,
 * usando as tabelas max_uso / max_limites do banco local (master_school_teste).
 */
class UsageLimiter
{
    public function verificar(int $usuarioId, string $funcao): array
    {
        $mesAtual = now()->format('Y-m');

        $limite = (int) (DB::table('max_limites')
            ->where('funcao', $funcao)
            ->value('limite_mensal') ?? config('max.limite_padrao'));

        $usoAtual = (int) (DB::table('max_uso')
            ->where('usuario_id', $usuarioId)
            ->where('mes_ano', $mesAtual)
            ->value('total_mensagens') ?? 0);

        return [
            'permitido' => $usoAtual < $limite,
            'uso_atual' => $usoAtual,
            'limite'    => $limite,
            'restantes' => max(0, $limite - $usoAtual),
        ];
    }

    public function registrar(int $usuarioId, int $tokensEntrada, int $tokensSaida): void
    {
        $mesAtual = now()->format('Y-m');
        $custo = ($tokensEntrada * 0.000003) + ($tokensSaida * 0.000015);

        // Mesma semantica do INSERT ... ON DUPLICATE KEY UPDATE do agent.php original
        // (requer indice unico em usuario_id + mes_ano, ja existente no schema legado).
        DB::statement('
            INSERT INTO max_uso
                (usuario_id, mes_ano, total_mensagens, tokens_entrada, tokens_saida, custo_estimado, ultima_mensagem)
            VALUES (?, ?, 1, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_mensagens = total_mensagens + 1,
                tokens_entrada  = tokens_entrada + VALUES(tokens_entrada),
                tokens_saida    = tokens_saida + VALUES(tokens_saida),
                custo_estimado  = custo_estimado + VALUES(custo_estimado),
                ultima_mensagem = NOW()
        ', [$usuarioId, $mesAtual, $tokensEntrada, $tokensSaida, $custo]);
    }
}
