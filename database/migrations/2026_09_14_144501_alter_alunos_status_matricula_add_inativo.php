<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * O código legado (api/alunos/inativar_aluno.php, portado em
     * AlunoController::inativar()) grava status_matricula = 'Inativo' ao
     * inativar um aluno, mas esse valor não existia no ENUM local — só
     * Ativo/Trancado/Transferido/Concluido. Extensão puramente aditiva
     * (não remove nem renomeia nenhum valor existente).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE alunos MODIFY status_matricula ENUM('Ativo','Trancado','Transferido','Concluido','Inativo') NULL DEFAULT 'Ativo'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE alunos MODIFY status_matricula ENUM('Ativo','Trancado','Transferido','Concluido') NULL DEFAULT 'Ativo'");
    }
};
