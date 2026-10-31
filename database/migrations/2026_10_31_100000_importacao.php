<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Importação de dados (implantação de escola nova): cada planilha importada
     * vira uma linha aqui, com os ids de tudo o que ela criou — é o que permite
     * "Desfazer esta importação" enquanto nada foi lançado em cima dos alunos.
     *
     * Compatível com MySQL 5.7 e retomável.
     */
    public function up(): void
    {
        if (!Schema::hasTable('importacoes')) {
            DB::statement("
                CREATE TABLE importacoes (
                    id INT NOT NULL AUTO_INCREMENT,
                    arquivo VARCHAR(150) NULL,
                    ano_letivo INT NOT NULL,
                    alunos INT NOT NULL DEFAULT 0,
                    responsaveis INT NOT NULL DEFAULT 0,
                    turmas INT NOT NULL DEFAULT 0,
                    criados TEXT NOT NULL COMMENT 'JSON: ids criados por tabela (para desfazer)',
                    criado_por INT NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    desfeita_em DATETIME NULL,
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('importacoes');
    }
};
