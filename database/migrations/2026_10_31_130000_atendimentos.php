<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Atendimentos (reunião da família com a escola, marcada pelo app).
     *
     * - atendimento_janelas: quem atende (professor, coordenação, direção,
     *   secretaria) abre horários num dia — das 14:00 às 16:00, de 20 em 20 min,
     *   presencial e/ou online (com o local ou o link).
     * - atendimentos: a família marca um desses horários para um filho, com o
     *   assunto; os dois lados podem cancelar, e quem atende marca como feito.
     *
     * Compatível com MySQL 5.7 e retomável.
     */
    public function up(): void
    {
        if (!Schema::hasTable('atendimento_janelas')) {
            DB::statement("
                CREATE TABLE atendimento_janelas (
                    id INT NOT NULL AUTO_INCREMENT,
                    usuario_id INT NOT NULL COMMENT 'quem atende',
                    data DATE NOT NULL,
                    inicio TIME NOT NULL,
                    fim TIME NOT NULL,
                    duracao SMALLINT NOT NULL DEFAULT 20 COMMENT 'minutos de cada atendimento',
                    modo ENUM('presencial','online','ambos') NOT NULL DEFAULT 'presencial',
                    local VARCHAR(200) NULL COMMENT 'sala (presencial) ou link (online)',
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_janela_usuario (usuario_id, data)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('atendimentos')) {
            DB::statement("
                CREATE TABLE atendimentos (
                    id INT NOT NULL AUTO_INCREMENT,
                    janela_id INT NOT NULL,
                    atendente_usuario_id INT NOT NULL,
                    inicio DATETIME NOT NULL,
                    fim DATETIME NOT NULL,
                    aluno_id INT NOT NULL,
                    familia_usuario_id INT NOT NULL COMMENT 'quem marcou',
                    assunto VARCHAR(300) NULL,
                    modo ENUM('presencial','online') NOT NULL DEFAULT 'presencial',
                    status ENUM('marcado','cancelado','realizado') NOT NULL DEFAULT 'marcado',
                    cancelado_por INT NULL,
                    motivo VARCHAR(300) NULL COMMENT 'motivo do cancelamento',
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_atend_atendente (atendente_usuario_id, inicio),
                    KEY idx_atend_familia (familia_usuario_id, inicio),
                    KEY idx_atend_aluno (aluno_id),
                    KEY idx_atend_janela (janela_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atendimentos');
        Schema::dropIfExists('atendimento_janelas');
    }
};
