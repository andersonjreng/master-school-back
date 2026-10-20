<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A inativação de aluno cancelava a matrícula mas não desfazia o vínculo
     * em turma_aluno. Esses alunos ficavam "presos" na turma: não apareciam na
     * virada de ano (que lista só ativos) e impediam encerrar a turma.
     * Remove o vínculo quando o aluno está inativo e não tem matrícula ativa
     * naquela turma (o histórico continua em `matriculas`).
     */
    public function up(): void
    {
        DB::statement("
            DELETE ta FROM turma_aluno ta
            JOIN alunos a ON a.id = ta.aluno_id
            JOIN usuarios u ON u.id = a.usuario_id
            WHERE u.ativo = 0
              AND NOT EXISTS (
                  SELECT 1 FROM matriculas m
                  WHERE m.aluno_id = ta.aluno_id AND m.turma_id = ta.turma_id AND m.status = 'ativa'
              )
        ");
    }

    public function down(): void
    {
        // Limpeza de dados inconsistentes — sem volta.
    }
};
