<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * api/admin/post_turma.php grava data_inicio/data_fim = '0000-00-00' ao criar
 * os 4 bimestres automáticos de um ano_letivo novo (campo nunca é preenchido
 * de fato depois — os dados de produção confirmam isso, todo ano_letivo
 * existente tem os 4 bimestres com '0000-00-00'). O MySQL local roda em modo
 * estrito (sem NO_ZERO_DATE), que rejeita esse valor — produção aceita porque
 * não está em modo estrito. Em vez de inserir uma data zero inválida (ou
 * inventar uma data real que não existe), a coluna passa a aceitar NULL, que
 * é a representação correta de "não preenchido" em qualquer modo do MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE unidades_letivas MODIFY data_inicio DATE NULL');
        DB::statement('ALTER TABLE unidades_letivas MODIFY data_fim DATE NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE unidades_letivas MODIFY data_inicio DATE NOT NULL');
        DB::statement('ALTER TABLE unidades_letivas MODIFY data_fim DATE NOT NULL');
    }
};
