<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notificações push (aviso na tela do celular/computador com o app fechado).
     *
     * - push_chaves: par de chaves VAPID da escola (gerado sozinho no 1º uso,
     *   sem mexer no .env; cada escola tem o seu banco).
     * - push_inscricoes: cada aparelho/navegador em que o usuário ativou os
     *   avisos. endpoint_hash = sha1 do endpoint (o endpoint passa de 255 chars).
     *
     * Compatível com MySQL 5.7 e retomável.
     */
    public function up(): void
    {
        if (!Schema::hasTable('push_chaves')) {
            DB::statement("
                CREATE TABLE push_chaves (
                    id INT NOT NULL AUTO_INCREMENT,
                    chave_publica VARCHAR(255) NOT NULL,
                    chave_privada VARCHAR(255) NOT NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('push_inscricoes')) {
            DB::statement("
                CREATE TABLE push_inscricoes (
                    id INT NOT NULL AUTO_INCREMENT,
                    usuario_id INT NOT NULL,
                    endpoint TEXT NOT NULL,
                    endpoint_hash CHAR(40) NOT NULL,
                    p256dh VARCHAR(255) NOT NULL,
                    auth VARCHAR(255) NOT NULL,
                    user_agent VARCHAR(255) NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    ultimo_envio DATETIME NULL,
                    falhas TINYINT NOT NULL DEFAULT 0,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_push_endpoint (endpoint_hash),
                    INDEX idx_push_usuario (usuario_id),
                    CONSTRAINT fk_push_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_inscricoes');
        Schema::dropIfExists('push_chaves');
    }
};
