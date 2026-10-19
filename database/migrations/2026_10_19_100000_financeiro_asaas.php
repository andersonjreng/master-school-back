<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Financeiro: cobrança pelo gateway Asaas (boleto com Pix + baixa automática).
     *
     * Cada escola tem a própria conta Asaas — por isso tudo fica no banco da
     * escola (multi-tenant por banco), inclusive a chave da API (criptografada).
     *
     * - asaas_configuracao: uma linha só (id = 1). Ambiente (sandbox | producao),
     *   chave da API e token do webhook, ambos criptografados com a APP_KEY.
     * - asaas_clientes: responsável pagador ↔ "customer" do Asaas. Separado por
     *   ambiente: o cliente criado no sandbox não existe em produção.
     * - asaas_cobrancas: parcela ↔ "payment" do Asaas. Uma parcela pode ter
     *   várias ao longo do tempo (ex.: cancelada e emitida de novo), mas só uma
     *   ativa (ativa = 1 → única por parcela via coluna gerada).
     * - asaas_webhook_eventos: todo evento recebido, guardado antes de
     *   processar. O id do evento é único — o Asaas entrega "pelo menos uma
     *   vez", então o mesmo evento pode chegar repetido.
     * - modo_cobranca passa a aceitar 'asaas' (validado na aplicação).
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE asaas_configuracao (
                id TINYINT NOT NULL,
                ambiente ENUM('sandbox','producao') NOT NULL DEFAULT 'sandbox',
                api_key TEXT NULL COMMENT 'Criptografada (Crypt::encryptString)',
                webhook_token TEXT NULL COMMENT 'Criptografado (Crypt::encryptString)',
                conta_nome VARCHAR(200) NULL,
                conta_documento VARCHAR(20) NULL,
                conta_email VARCHAR(200) NULL,
                testado_em DATETIME NULL,
                atualizado_por INT NULL,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE asaas_clientes (
                id INT NOT NULL AUTO_INCREMENT,
                responsavel_id INT NOT NULL,
                ambiente ENUM('sandbox','producao') NOT NULL,
                customer_id VARCHAR(40) NOT NULL,
                sincronizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_asaas_cliente (responsavel_id, ambiente),
                UNIQUE KEY uq_asaas_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE asaas_cobrancas (
                id INT NOT NULL AUTO_INCREMENT,
                parcela_id INT NOT NULL,
                ambiente ENUM('sandbox','producao') NOT NULL,
                payment_id VARCHAR(40) NOT NULL,
                customer_id VARCHAR(40) NOT NULL,
                status VARCHAR(40) NOT NULL COMMENT 'Status do lado do Asaas (PENDING, RECEIVED, OVERDUE...)',
                valor DECIMAL(10,2) NOT NULL,
                vencimento DATE NOT NULL,
                invoice_url VARCHAR(255) NULL,
                bank_slip_url VARCHAR(255) NULL,
                nosso_numero VARCHAR(30) NULL,
                linha_digitavel VARCHAR(60) NULL,
                pix_copia_cola TEXT NULL,
                valor_pago DECIMAL(10,2) NULL,
                data_pagamento DATE NULL,
                baixa_id INT NULL,
                ativa TINYINT(1) NOT NULL DEFAULT 1,
                parcela_ativa INT GENERATED ALWAYS AS (IF(ativa = 1, parcela_id, NULL)) STORED,
                ultimo_evento VARCHAR(60) NULL,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                cancelado_em DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_asaas_payment (payment_id),
                UNIQUE KEY uq_asaas_parcela_ativa (parcela_ativa),
                INDEX idx_asaas_cob_parcela (parcela_id),
                INDEX idx_asaas_cob_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE asaas_webhook_eventos (
                id INT NOT NULL AUTO_INCREMENT,
                evento_id VARCHAR(60) NOT NULL,
                evento VARCHAR(60) NOT NULL,
                payment_id VARCHAR(40) NULL,
                payload LONGTEXT NOT NULL,
                recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processado_em DATETIME NULL,
                resultado ENUM('pendente','aplicado','ignorado','erro') NOT NULL DEFAULT 'pendente',
                mensagem VARCHAR(255) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_asaas_evento (evento_id),
                INDEX idx_asaas_evt_payment (payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::table('configuracoes_financeiras')->where('chave', 'modo_cobranca')
            ->update(['descricao' => 'manual = só controle interno; sicoob_arquivo = boletos Sicoob por remessa/retorno (CNAB 400); asaas = boleto com Pix pelo Asaas']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS asaas_webhook_eventos');
        DB::statement('DROP TABLE IF EXISTS asaas_cobrancas');
        DB::statement('DROP TABLE IF EXISTS asaas_clientes');
        DB::statement('DROP TABLE IF EXISTS asaas_configuracao');
        DB::table('configuracoes_financeiras')->where('chave', 'modo_cobranca')->where('valor', 'asaas')->update(['valor' => 'manual']);
    }
};
