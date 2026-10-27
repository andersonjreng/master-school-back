<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rematrícula e virada de ano num fluxo só.
     *
     * - matriculas.numero: número de matrícula daquele ano letivo (ex.: 2027001).
     *   O aluno ganha um número novo a cada ano; alunos.matricula guarda o atual.
     * - matriculas.resultado: como o ano terminou (promovido, retido, concluiu,
     *   não renovou) — vai para o histórico de matrículas.
     * - rematriculas: número reservado na rematrícula, quem fez (família pelo
     *   app ou secretaria) e a matrícula criada quando a turma de origem é
     *   encerrada (efetivação).
     * - matricula_numeracao: último número usado por ano (trava a sequência
     *   para dois atendimentos ao mesmo tempo não pegarem o mesmo número).
     *
     * Compatível com MySQL 5.7 e retomável.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('matriculas', 'numero')) {
            DB::statement("ALTER TABLE matriculas ADD COLUMN numero VARCHAR(20) NULL COMMENT 'Número de matrícula no ano letivo' AFTER ano_letivo, ADD INDEX idx_matriculas_numero (numero)");
        }
        if (!Schema::hasColumn('matriculas', 'resultado')) {
            DB::statement("ALTER TABLE matriculas ADD COLUMN resultado ENUM('promovido','retido','concluiu','nao_renovou') NULL AFTER status");
        }

        // A matrícula em curso ganha o número que o aluno usa hoje.
        DB::statement("
            UPDATE matriculas m JOIN alunos a ON a.id = m.aluno_id
            SET m.numero = a.matricula
            WHERE m.status = 'ativa' AND m.numero IS NULL
        ");

        if (Schema::hasTable('rematriculas')) {
            if (!Schema::hasColumn('rematriculas', 'numero_matricula')) {
                DB::statement("ALTER TABLE rematriculas ADD COLUMN numero_matricula VARCHAR(20) NULL AFTER turma_destino_id, ADD INDEX idx_rematricula_numero (numero_matricula)");
            }
            if (!Schema::hasColumn('rematriculas', 'origem')) {
                DB::statement("ALTER TABLE rematriculas ADD COLUMN origem ENUM('familia','secretaria') NOT NULL DEFAULT 'familia' AFTER usuario_id");
            }
            if (!Schema::hasColumn('rematriculas', 'atendido_por')) {
                DB::statement("ALTER TABLE rematriculas ADD COLUMN atendido_por INT NULL AFTER origem");
            }
            if (!Schema::hasColumn('rematriculas', 'matricula_id')) {
                DB::statement("ALTER TABLE rematriculas ADD COLUMN matricula_id INT NULL AFTER parcela_cobranca_id, ADD COLUMN efetivada_em DATETIME NULL AFTER confirmada_em");
            }
        }

        if (!Schema::hasTable('matricula_numeracao')) {
            DB::statement("
                CREATE TABLE matricula_numeracao (
                    ano INT NOT NULL,
                    ultimo INT NOT NULL DEFAULT 0,
                    PRIMARY KEY (ano)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // Rematrículas já feitas (antes desta versão) recebem o número do ano novo.
        if (Schema::hasTable('rematriculas')) {
            $pendentes = DB::table('rematriculas as r')->join('rematricula_campanhas as c', 'c.id', '=', 'r.campanha_id')
                ->whereIn('r.status', ['aguardando_pagamento', 'confirmada'])->whereNull('r.numero_matricula')
                ->orderBy('r.aceite_em')->orderBy('r.id')->get(['r.id', 'c.ano_destino']);
            foreach ($pendentes as $r) {
                $numero = app(\App\Services\Matriculas\NumeroMatriculaService::class)->proximo((int) $r->ano_destino);
                DB::table('rematriculas')->where('id', $r->id)->update(['numero_matricula' => $numero]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_numeracao');
        if (Schema::hasColumn('rematriculas', 'numero_matricula')) {
            DB::statement('ALTER TABLE rematriculas DROP INDEX idx_rematricula_numero, DROP COLUMN numero_matricula, DROP COLUMN origem, DROP COLUMN atendido_por, DROP COLUMN matricula_id, DROP COLUMN efetivada_em');
        }
        if (Schema::hasColumn('matriculas', 'numero')) {
            DB::statement('ALTER TABLE matriculas DROP INDEX idx_matriculas_numero, DROP COLUMN numero, DROP COLUMN resultado');
        }
    }
};
