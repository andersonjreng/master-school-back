<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Como cada pessoa acessa o sistema (quadro "Como acessam" dos Logs).
     *
     * - origem: "app" (app instalado no aparelho) ou "web" (navegador) —
     *   header X-Origem do front. Só existe daqui pra frente.
     * - dispositivo: celular, tablet ou computador, pelo navegador
     *   (LogSistemaService::dispositivo). O histórico é preenchido aqui.
     *
     * MySQL 5.7 e retomável, como a 2026_10_22.
     */
    public function up(): void
    {
        $colunas = [
            'origem'      => "VARCHAR(10) NULL AFTER user_agent",
            'dispositivo' => "VARCHAR(12) NULL AFTER origem",
        ];
        foreach ($colunas as $coluna => $definicao) {
            if (!Schema::hasColumn('logs_sistema', $coluna)) {
                DB::statement("ALTER TABLE logs_sistema ADD COLUMN $coluna $definicao");
            }
        }

        // Mesma regra de LogSistemaService::dispositivo(), em SQL.
        DB::statement("
            UPDATE logs_sistema SET dispositivo = CASE
                WHEN user_agent LIKE '%iPad%' OR (user_agent LIKE '%Android%' AND user_agent NOT LIKE '%Mobile%') THEN 'tablet'
                WHEN user_agent LIKE '%Mobi%' OR user_agent LIKE '%iPhone%' OR user_agent LIKE '%Android%' THEN 'celular'
                ELSE 'computador' END
            WHERE dispositivo IS NULL AND user_agent IS NOT NULL
        ");
    }

    public function down(): void
    {
        foreach (['dispositivo', 'origem'] as $coluna) {
            if (Schema::hasColumn('logs_sistema', $coluna)) {
                DB::statement("ALTER TABLE logs_sistema DROP COLUMN $coluna");
            }
        }
    }
};
