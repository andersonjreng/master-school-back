<?php

namespace App\Services\Financeiro\Asaas;

use Illuminate\Support\Facades\DB;

/**
 * Histórico das rotinas agendadas do Asaas (asaas_rotinas): cada execução da
 * emissão automática e da sincronização deixa uma linha, e a Central mostra
 * a última — é como a escola sabe que "o robô" está rodando.
 */
class AsaasRotinasService
{
    public function registrar(string $rotina, string $resumo, array $detalhes = []): void
    {
        if (!DB::getSchemaBuilder()->hasTable('asaas_rotinas')) {
            return;
        }
        DB::table('asaas_rotinas')->insert([
            'rotina'   => $rotina,
            'resumo'   => mb_substr($resumo, 0, 255),
            'detalhes' => $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null,
        ]);
        // Guarda só os últimos 90 dias.
        DB::table('asaas_rotinas')->where('executado_em', '<', now()->subDays(90))->delete();
    }

    /** Última execução de cada rotina: ['emissao' => [...]|null, 'sincronizacao' => [...]|null]. */
    public function ultimas(): array
    {
        $r = ['emissao' => null, 'sincronizacao' => null];
        if (!DB::getSchemaBuilder()->hasTable('asaas_rotinas')) {
            return $r;
        }
        foreach (array_keys($r) as $rotina) {
            $linha = DB::table('asaas_rotinas')->where('rotina', $rotina)->orderByDesc('id')->first(['executado_em', 'resumo']);
            $r[$rotina] = $linha ? (array) $linha : null;
        }
        return $r;
    }
}
