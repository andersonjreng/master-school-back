<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Preparação para a virada de ano letivo.
     *
     * 1. Nome de turma único POR ANO (antes era único no sistema todo, o que
     *    impedia criar "5º Ano" em 2027 se existisse "5º Ano" em 2026).
     * 2. turmas.encerrada_em — quando a turma foi encerrada (congelada).
     * 3. Promoções antigas foram gravadas como 'transferida'; quando o aluno
     *    seguiu para um ano letivo posterior, o correto é 'concluida' (o ano
     *    foi concluído). Isso permite que a turma encerrada mostre seus alunos.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE turmas DROP INDEX nome, ADD UNIQUE KEY uq_turma_nome_ano (nome_turma, ano_letivo)');
        DB::statement('ALTER TABLE turmas ADD COLUMN encerrada_em DATETIME NULL AFTER status');
        DB::statement("UPDATE turmas SET encerrada_em = NOW() WHERE status = 'Encerrada'");

        DB::statement("
            UPDATE matriculas m
            JOIN (
                SELECT DISTINCT m1.id
                FROM matriculas m1
                JOIN matriculas m2 ON m2.aluno_id = m1.aluno_id AND m2.ano_letivo > m1.ano_letivo
                WHERE m1.status = 'transferida'
            ) promovidas ON promovidas.id = m.id
            SET m.status = 'concluida', m.motivo_encerramento = COALESCE(m.motivo_encerramento, 'conclusao')
        ");

        // Ano letivo registrado para todo ano que já tem turma.
        DB::statement('INSERT IGNORE INTO anos_letivos (ano) SELECT DISTINCT ano_letivo FROM turmas');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE turmas DROP COLUMN encerrada_em');
        DB::statement('ALTER TABLE turmas DROP INDEX uq_turma_nome_ano, ADD UNIQUE KEY nome (nome_turma)');
    }
};
