<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rematrícula online.
     *
     * - rematricula_campanhas: a escola abre a rematrícula de um ano (período,
     *   o que cobrar na hora, desconto antecipado opcional e o texto do contrato).
     * - rematricula_turmas: para qual turma do ano novo vai cada turma atual.
     * - rematriculas: a decisão de cada aluno — aceite do contrato (cópia do
     *   texto, hash, IP, aparelho e hora), contrato gerado e a parcela cobrada
     *   na hora; ou a recusa ("não vamos renovar") com o motivo.
     *
     * A taxa de matrícula, quando escolhida, é a parcela nº 0 do contrato.
     * Compatível com MySQL 5.7 e retomável.
     */
    public function up(): void
    {
        if (!Schema::hasTable('rematricula_campanhas')) {
            DB::statement("
                CREATE TABLE rematricula_campanhas (
                    id INT NOT NULL AUTO_INCREMENT,
                    ano_destino INT NOT NULL,
                    inicio DATE NOT NULL,
                    fim DATE NOT NULL,
                    cobranca ENUM('primeira_parcela','taxa','nenhuma') NOT NULL DEFAULT 'primeira_parcela',
                    taxa_valor DECIMAL(10,2) NULL,
                    prazo_pagamento_dias TINYINT NOT NULL DEFAULT 5,
                    desconto_tipo ENUM('percentual','valor') NULL,
                    desconto_valor DECIMAL(10,2) NULL,
                    desconto_ate DATE NULL,
                    contrato_modelo MEDIUMTEXT NOT NULL,
                    status ENUM('rascunho','aberta','encerrada') NOT NULL DEFAULT 'rascunho',
                    criado_por INT NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_rematricula_campanha_ano (ano_destino)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('rematricula_turmas')) {
            DB::statement("
                CREATE TABLE rematricula_turmas (
                    campanha_id INT NOT NULL,
                    turma_origem_id INT NOT NULL,
                    turma_destino_id INT NOT NULL,
                    PRIMARY KEY (campanha_id, turma_origem_id),
                    CONSTRAINT fk_remat_turma_campanha FOREIGN KEY (campanha_id) REFERENCES rematricula_campanhas (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('rematriculas')) {
            DB::statement("
                CREATE TABLE rematriculas (
                    id INT NOT NULL AUTO_INCREMENT,
                    campanha_id INT NOT NULL,
                    aluno_id INT NOT NULL,
                    responsavel_id INT NULL,
                    usuario_id INT NULL,
                    turma_origem_id INT NULL,
                    turma_destino_id INT NULL,
                    plano_id INT NULL,
                    status ENUM('aguardando_pagamento','confirmada','recusada','cancelada') NOT NULL,
                    motivo_recusa VARCHAR(255) NULL,
                    matricula_financeira_id INT NULL,
                    parcela_cobranca_id INT NULL,
                    desconto_antecipado TINYINT(1) NOT NULL DEFAULT 0,
                    contrato_html MEDIUMTEXT NULL,
                    contrato_hash CHAR(64) NULL,
                    aceite_em DATETIME NULL,
                    aceite_ip VARCHAR(45) NULL,
                    aceite_user_agent VARCHAR(255) NULL,
                    confirmada_em DATETIME NULL,
                    cancelada_por INT NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_rematricula_aluno (campanha_id, aluno_id),
                    INDEX idx_rematricula_status (campanha_id, status),
                    INDEX idx_rematricula_parcela (parcela_cobranca_id),
                    CONSTRAINT fk_rematricula_campanha FOREIGN KEY (campanha_id) REFERENCES rematricula_campanhas (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rematriculas');
        Schema::dropIfExists('rematricula_turmas');
        Schema::dropIfExists('rematricula_campanhas');
    }
};
