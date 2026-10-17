<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Briefing diário do Max no Painel da Direção: um por dia (e ano letivo),
     * gerado no primeiro acesso e reaproveitado o resto do dia. A opção de
     * desligar fica em configuracoes_escola (max_briefing_diario = 1/0).
     */
    public function up(): void
    {
        DB::statement('
            CREATE TABLE max_briefings (
                id INT NOT NULL AUTO_INCREMENT,
                data DATE NOT NULL,
                ano_letivo INT NOT NULL,
                conteudo JSON NOT NULL,
                tokens_entrada INT NOT NULL DEFAULT 0,
                tokens_saida INT NOT NULL DEFAULT 0,
                custo_estimado DECIMAL(10,6) NOT NULL DEFAULT 0,
                gerado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_briefing_dia (data, ano_letivo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        DB::table('configuracoes_escola')->insertOrIgnore([
            'chave' => 'max_briefing_diario', 'valor' => '1',
            'descricao' => 'Briefing diário do Max no painel da direção (1 = ligado, 0 = desligado)',
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS max_briefings');
        DB::table('configuracoes_escola')->where('chave', 'max_briefing_diario')->delete();
    }
};
