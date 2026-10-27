<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quadro de horários.
     *
     * - horario_modelos + horario_faixas: o "sinal" da escola — as faixas de
     *   horário de um turno (1ª aula 07:00–07:50, recreio...). Um modelo serve
     *   para várias turmas; mudar o horário do recreio é mudar num lugar só.
     * - horario_turmas: qual modelo e quais dias da semana cada turma usa.
     * - horario_aulas: a grade — em cada dia × faixa, a disciplina (e o
     *   professor, se não for o do vínculo, e a sala).
     *
     * Compatível com MySQL 5.7 e retomável.
     */
    public function up(): void
    {
        if (!Schema::hasTable('horario_modelos')) {
            DB::statement("
                CREATE TABLE horario_modelos (
                    id INT NOT NULL AUTO_INCREMENT,
                    nome VARCHAR(80) NOT NULL,
                    turno VARCHAR(20) NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('horario_faixas')) {
            DB::statement("
                CREATE TABLE horario_faixas (
                    id INT NOT NULL AUTO_INCREMENT,
                    modelo_id INT NOT NULL,
                    ordem TINYINT NOT NULL,
                    inicio TIME NOT NULL,
                    fim TIME NOT NULL,
                    tipo ENUM('aula','intervalo') NOT NULL DEFAULT 'aula',
                    rotulo VARCHAR(40) NULL,
                    PRIMARY KEY (id),
                    INDEX idx_faixa_modelo (modelo_id, ordem),
                    CONSTRAINT fk_faixa_modelo FOREIGN KEY (modelo_id) REFERENCES horario_modelos (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('horario_turmas')) {
            DB::statement("
                CREATE TABLE horario_turmas (
                    turma_id INT NOT NULL,
                    modelo_id INT NOT NULL,
                    dias VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5' COMMENT '1 = segunda ... 6 = sábado',
                    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_por INT NULL,
                    avisado_em DATETIME NULL COMMENT 'Última vez que as famílias foram avisadas da mudança',
                    PRIMARY KEY (turma_id),
                    CONSTRAINT fk_horario_turma_modelo FOREIGN KEY (modelo_id) REFERENCES horario_modelos (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('horario_aulas')) {
            DB::statement("
                CREATE TABLE horario_aulas (
                    id INT NOT NULL AUTO_INCREMENT,
                    turma_id INT NOT NULL,
                    dia_semana TINYINT NOT NULL COMMENT '1 = segunda ... 6 = sábado',
                    faixa_id INT NOT NULL,
                    disciplina_id INT NOT NULL,
                    professor_id INT NULL COMMENT 'NULL = o professor da disciplina na turma',
                    sala VARCHAR(40) NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_horario_aula (turma_id, dia_semana, faixa_id),
                    INDEX idx_horario_professor (professor_id),
                    CONSTRAINT fk_aula_faixa FOREIGN KEY (faixa_id) REFERENCES horario_faixas (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('horario_aulas');
        Schema::dropIfExists('horario_turmas');
        Schema::dropIfExists('horario_faixas');
        Schema::dropIfExists('horario_modelos');
    }
};
