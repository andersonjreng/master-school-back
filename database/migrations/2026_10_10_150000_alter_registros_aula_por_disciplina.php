<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registro de aula passa a ser por disciplina: o professor lança um
     * registro para cada disciplina que leciona na turma, no mesmo dia.
     * Antes o índice único era (turma, professor, data) e a disciplina era
     * texto livre. `disciplina` (nome) continua sendo gravado junto, para o
     * resumo mensal e registros antigos sem vínculo.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE registros_aula ADD COLUMN disciplina_id INT NULL AFTER professor_id');

        // Vincula registros antigos pela disciplina de mesmo nome.
        DB::statement('
            UPDATE registros_aula ra
            JOIN disciplinas d ON d.nome_disciplina = ra.disciplina
            SET ra.disciplina_id = d.id
        ');

        DB::statement('ALTER TABLE registros_aula DROP INDEX uq_registro_professor_turma_dia');
        DB::statement('ALTER TABLE registros_aula ADD UNIQUE KEY uq_registro_turma_disciplina_dia (turma_id, professor_id, disciplina_id, data)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE registros_aula DROP INDEX uq_registro_turma_disciplina_dia');
        DB::statement('ALTER TABLE registros_aula ADD UNIQUE KEY uq_registro_professor_turma_dia (turma_id, professor_id, data)');
        DB::statement('ALTER TABLE registros_aula DROP COLUMN disciplina_id');
    }
};
