<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notificações do sino por usuário (nota lançada, avaliação marcada,
     * falta, boleto...). `chave` identifica o assunto (ex.: nota:12:345) e é
     * única por usuário: o mesmo assunto atualiza a notificação em vez de
     * duplicar (nota corrigida, avaliação remarcada).
     *
     * Compatível com MySQL 5.7 e retomável.
     */
    public function up(): void
    {
        if (Schema::hasTable('notificacoes')) {
            return;
        }
        DB::statement("
            CREATE TABLE notificacoes (
                id INT NOT NULL AUTO_INCREMENT,
                usuario_id INT NOT NULL,
                tipo VARCHAR(30) NOT NULL,
                chave VARCHAR(80) NOT NULL,
                aluno_id INT NULL,
                titulo VARCHAR(160) NOT NULL,
                detalhe VARCHAR(255) NULL,
                link VARCHAR(120) NULL,
                link_query VARCHAR(255) NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lida_em DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_notificacao_usuario_chave (usuario_id, chave),
                INDEX idx_notificacao_usuario_lida (usuario_id, lida_em),
                INDEX idx_notificacao_atualizado (atualizado_em),
                CONSTRAINT fk_notificacao_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacoes');
    }
};
