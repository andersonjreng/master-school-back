<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agenda da Educação Infantil: a rotina do dia de cada criança para a família.
     *
     * - agenda_itens: o que a escola acompanha (lanche, sono, humor, fralda...) e
     *   como se responde — escolha entre opções (com emoji) ou contador.
     * - agenda_dias: o dia de uma turma — as respostas "da turma toda", o
     *   recado geral e quando foi enviado às famílias (antes disso, só a
     *   escola vê).
     * - agenda_alunos: o que foge da turma toda para cada criança (exceções),
     *   o recado individual e quando a família viu.
     *
     * Compatível com MySQL 5.7 (JSON guardado em TEXT) e retomável.
     */
    public function up(): void
    {
        if (!Schema::hasTable('agenda_itens')) {
            DB::statement("
                CREATE TABLE agenda_itens (
                    id INT NOT NULL AUTO_INCREMENT,
                    nome VARCHAR(60) NOT NULL,
                    emoji VARCHAR(16) NULL,
                    tipo ENUM('opcoes','contador') NOT NULL DEFAULT 'opcoes',
                    opcoes TEXT NULL COMMENT 'JSON [{id, rotulo, emoji, tom: bom|medio|ruim}]',
                    etapas VARCHAR(120) NULL COMMENT 'etapas da BNCC separadas por | (NULL = todas)',
                    ordem SMALLINT NOT NULL DEFAULT 0,
                    ativo TINYINT(1) NOT NULL DEFAULT 1,
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('agenda_dias')) {
            DB::statement("
                CREATE TABLE agenda_dias (
                    id INT NOT NULL AUTO_INCREMENT,
                    turma_id INT NOT NULL,
                    data DATE NOT NULL,
                    padroes TEXT NULL COMMENT 'JSON {item_id: valor} — vale para todas as crianças sem exceção',
                    recado TEXT NULL COMMENT 'Recado para a turma toda',
                    enviado_em DATETIME NULL,
                    enviado_por INT NULL,
                    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_por INT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_agenda_dia (turma_id, data)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('agenda_alunos')) {
            DB::statement("
                CREATE TABLE agenda_alunos (
                    id INT NOT NULL AUTO_INCREMENT,
                    dia_id INT NOT NULL,
                    aluno_id INT NOT NULL,
                    valores TEXT NULL COMMENT 'JSON {item_id: valor} — exceções ao padrão da turma',
                    recado TEXT NULL,
                    visto_em DATETIME NULL COMMENT 'Primeira vez que a família abriu',
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_agenda_aluno (dia_id, aluno_id),
                    INDEX idx_agenda_aluno (aluno_id),
                    CONSTRAINT fk_agenda_aluno_dia FOREIGN KEY (dia_id) REFERENCES agenda_dias (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_alunos');
        Schema::dropIfExists('agenda_dias');
        Schema::dropIfExists('agenda_itens');
    }
};
