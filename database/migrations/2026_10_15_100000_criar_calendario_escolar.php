<?php

use App\Services\Turmas\CalendarioEscolarService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Calendário escolar: feriados, recessos, sábados letivos e eventos de
     * cada ano letivo, em períodos (data_inicio..data_fim). Com as datas dos
     * bimestres, define o que é dia letivo (CalendarioEscolarService).
     * Já importa os feriados nacionais dos anos letivos cadastrados.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE calendario_escolar (
                id INT NOT NULL AUTO_INCREMENT,
                ano_letivo INT NOT NULL,
                data_inicio DATE NOT NULL,
                data_fim DATE NOT NULL,
                tipo VARCHAR(20) NOT NULL COMMENT 'feriado | recesso | sabado_letivo | evento',
                descricao VARCHAR(150) NOT NULL,
                origem VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual | nacional',
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_calendario_ano (ano_letivo, data_inicio)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $servico = app(CalendarioEscolarService::class);
        foreach (DB::table('anos_letivos')->pluck('ano') as $ano) {
            $servico->importarNacionais((int) $ano);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS calendario_escolar');
    }
};
