<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Calendário escolar: sigla opcional de cada marcação (aparece no
     * calendário impresso — ex.: EP = Encontro Pedagógico) e o tipo
     * "ponto_facultativo" (dia sem aula, como feriado).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE calendario_escolar
            ADD COLUMN sigla VARCHAR(4) NULL AFTER descricao,
            MODIFY tipo VARCHAR(20) NOT NULL COMMENT 'feriado | ponto_facultativo | recesso | sabado_letivo | evento'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE calendario_escolar DROP COLUMN sigla');
    }
};
