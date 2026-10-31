<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mural turbinado.
     *
     * - Publicação agendada: publicar_em (NULL = na hora). Até lá só o autor e a
     *   gestão veem; avisado_em marca quando o aviso no celular saiu (uma vez só).
     * - Enquete: o comunicado pode ter opções para votar (uma ou várias), com o
     *   resultado aberto a quem votou ou só para quem publicou, e ser encerrada.
     *
     * Compatível com MySQL 5.7 e retomável.
     */
    public function up(): void
    {
        $colunas = [
            'publicar_em' => "ADD COLUMN publicar_em DATETIME NULL COMMENT 'NULL = publicado na criação' AFTER exige_ciencia",
            'avisado_em' => "ADD COLUMN avisado_em DATETIME NULL COMMENT 'Aviso no celular enviado (agendados)' AFTER publicar_em",
            'enquete' => "ADD COLUMN enquete TINYINT(1) NOT NULL DEFAULT 0 AFTER avisado_em",
            'enquete_multipla' => "ADD COLUMN enquete_multipla TINYINT(1) NOT NULL DEFAULT 0 AFTER enquete",
            'enquete_resultado' => "ADD COLUMN enquete_resultado VARCHAR(10) NOT NULL DEFAULT 'votou' COMMENT 'votou = quem votou vê | autor = só quem publicou' AFTER enquete_multipla",
            'enquete_encerrada_em' => "ADD COLUMN enquete_encerrada_em DATETIME NULL AFTER enquete_resultado",
        ];
        foreach ($colunas as $coluna => $sql) {
            if (!Schema::hasColumn('mural_posts', $coluna)) {
                DB::statement("ALTER TABLE mural_posts $sql");
            }
        }

        if (!Schema::hasTable('mural_enquete_opcoes')) {
            DB::statement("
                CREATE TABLE mural_enquete_opcoes (
                    id INT NOT NULL AUTO_INCREMENT,
                    post_id INT NOT NULL,
                    ordem TINYINT NOT NULL DEFAULT 0,
                    texto VARCHAR(120) NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_enquete_post (post_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('mural_votos')) {
            DB::statement("
                CREATE TABLE mural_votos (
                    opcao_id INT NOT NULL,
                    usuario_id INT NOT NULL,
                    post_id INT NOT NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (opcao_id, usuario_id),
                    KEY idx_voto_post (post_id, usuario_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mural_votos');
        Schema::dropIfExists('mural_enquete_opcoes');
        foreach (['enquete_encerrada_em', 'enquete_resultado', 'enquete_multipla', 'enquete', 'avisado_em', 'publicar_em'] as $c) {
            if (Schema::hasColumn('mural_posts', $c)) {
                DB::statement("ALTER TABLE mural_posts DROP COLUMN $c");
            }
        }
    }
};
