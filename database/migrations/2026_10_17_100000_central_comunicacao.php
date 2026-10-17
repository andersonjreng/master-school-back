<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Central de Comunicação:
     *
     * - mural_posts / mural_leituras / mural_reacoes: comunicados com público
     *   (escola, equipe, famílias ou turmas), fixar, validade, confirmação de
     *   leitura ("ciente") e reações. Família não comenta — só reage e confirma.
     * - ocorrencias: registro por aluno (elogio, comportamento, saúde…), interna
     *   (anotação da equipe) ou compartilhada com a família, com ciência.
     * - recados_conversas / recados_mensagens / recados_leituras: conversa
     *   privada por aluno entre a família e um professor (ou a secretaria).
     *   A direção tem acesso de auditoria a todas.
     * - comunicacao_whatsapp_enviados: dedup dos avisos por WhatsApp (o envio
     *   só liga quando o template for aprovado na Meta e configurado).
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE mural_posts (
                id INT NOT NULL AUTO_INCREMENT,
                autor_usuario_id INT NOT NULL,
                titulo VARCHAR(200) NOT NULL,
                texto TEXT NOT NULL,
                publico VARCHAR(12) NOT NULL COMMENT 'escola | equipe | familias | turmas',
                turmas JSON NULL COMMENT 'ids das turmas quando publico = turmas',
                fixado TINYINT(1) NOT NULL DEFAULT 0,
                valido_ate DATE NULL,
                exige_ciencia TINYINT(1) NOT NULL DEFAULT 0,
                anexo_path VARCHAR(255) NULL,
                anexo_nome VARCHAR(200) NULL,
                anexo_tipo VARCHAR(80) NULL,
                oculto TINYINT(1) NOT NULL DEFAULT 0,
                oculto_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_mural_data (criado_em),
                KEY idx_mural_autor (autor_usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        DB::statement("
            CREATE TABLE mural_leituras (
                post_id INT NOT NULL,
                usuario_id INT NOT NULL,
                lido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ciente_em DATETIME NULL,
                PRIMARY KEY (post_id, usuario_id),
                KEY idx_leitura_usuario (usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        DB::statement("
            CREATE TABLE mural_reacoes (
                post_id INT NOT NULL,
                usuario_id INT NOT NULL,
                emoji VARCHAR(8) NOT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (post_id, usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE ocorrencias (
                id INT NOT NULL AUTO_INCREMENT,
                aluno_id INT NOT NULL,
                turma_id INT NULL,
                autor_usuario_id INT NOT NULL,
                tipo VARCHAR(20) NOT NULL COMMENT 'elogio | comportamento | tarefa | atraso | saude | disciplinar | outro',
                nivel VARCHAR(10) NOT NULL DEFAULT 'info' COMMENT 'info | atencao | grave',
                data DATE NOT NULL,
                titulo VARCHAR(150) NOT NULL,
                descricao TEXT NULL,
                visibilidade VARCHAR(10) NOT NULL DEFAULT 'interna' COMMENT 'interna | familia',
                exige_ciencia TINYINT(1) NOT NULL DEFAULT 0,
                ciente_em DATETIME NULL,
                ciente_por INT NULL COMMENT 'usuario_id do responsável que confirmou',
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ocorrencia_aluno (aluno_id, data),
                KEY idx_ocorrencia_turma (turma_id, data),
                KEY idx_ocorrencia_nivel (nivel, data)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE recados_conversas (
                id INT NOT NULL AUTO_INCREMENT,
                aluno_id INT NOT NULL,
                escola_usuario_id INT NULL COMMENT 'professor da conversa; NULL = secretaria/direção',
                assunto VARCHAR(150) NOT NULL,
                criado_por INT NOT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ultima_mensagem_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                encerrada TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_conversa_aluno (aluno_id),
                KEY idx_conversa_escola (escola_usuario_id, ultima_mensagem_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        DB::statement("
            CREATE TABLE recados_mensagens (
                id INT NOT NULL AUTO_INCREMENT,
                conversa_id INT NOT NULL,
                autor_usuario_id INT NOT NULL,
                lado VARCHAR(8) NOT NULL COMMENT 'familia | escola',
                texto TEXT NOT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_mensagem_conversa (conversa_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        DB::statement("
            CREATE TABLE recados_leituras (
                conversa_id INT NOT NULL,
                usuario_id INT NOT NULL,
                ultima_lida_id INT NOT NULL DEFAULT 0,
                lido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (conversa_id, usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE comunicacao_whatsapp_enviados (
                id INT NOT NULL AUTO_INCREMENT,
                tipo VARCHAR(12) NOT NULL COMMENT 'mural | recado | ocorrencia',
                referencia_id INT NOT NULL,
                responsavel_id INT NOT NULL,
                status VARCHAR(20) NOT NULL,
                erro VARCHAR(255) NULL,
                enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_comunicacao_envio (tipo, referencia_id, responsavel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        foreach (['comunicacao_whatsapp_enviados', 'recados_leituras', 'recados_mensagens', 'recados_conversas', 'ocorrencias', 'mural_reacoes', 'mural_leituras', 'mural_posts'] as $t) {
            DB::statement("DROP TABLE IF EXISTS $t");
        }
    }
};
