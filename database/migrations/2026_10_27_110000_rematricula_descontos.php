<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desconto combinado com a família para o ano novo, registrado pela
     * secretaria antes da rematrícula: vale quando a família faz pelo app
     * (plano e contrato já saem com ele) e vem preenchido na secretaria.
     * desconto_padrao_id soma com o desconto antecipado da campanha; o extra
     * (tipo/valor) o substitui. Compatível com MySQL 5.7 e retomável.
     */
    public function up(): void
    {
        if (!Schema::hasTable('rematricula_descontos')) {
            DB::statement("
                CREATE TABLE rematricula_descontos (
                    campanha_id INT NOT NULL,
                    aluno_id INT NOT NULL,
                    desconto_padrao_id INT NULL,
                    extra_tipo ENUM('percentual','valor') NULL,
                    extra_valor DECIMAL(10,2) NULL,
                    descricao VARCHAR(200) NULL,
                    criado_por INT NULL,
                    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (campanha_id, aluno_id),
                    CONSTRAINT fk_remat_desc_campanha FOREIGN KEY (campanha_id) REFERENCES rematricula_campanhas (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rematricula_descontos');
    }
};
