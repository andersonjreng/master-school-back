<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Financeiro: cobrança parametrizável + ciclo completo do boleto Sicoob
     * (Cenário A, por arquivo) + integração com matrícula/virada de ano.
     *
     * - configuracoes_financeiras: modo de cobrança (manual | sicoob_arquivo),
     *   como o financeiro entra na matrícula, antecedência padrão da remessa,
     *   instruções do boleto.
     * - boletos_bancarios: novos status (registrado/rejeitado/baixado) e a
     *   última ocorrência informada pelo banco.
     * - remessas_sicoob: marcar como enviada ao banco + arquivo guardado para
     *   baixar de novo.
     * - retornos_sicoob: hash do arquivo (impede importar o mesmo duas vezes)
     *   e itens linha a linha (auditoria do que foi feito com cada título).
     * - plano_pagamento_turma: turmas para as quais o plano é sugerido.
     * - financeiro_dispensas: aluno que não terá contrato no ano (ex.: bolsa
     *   integral), para não aparecer como pendência "sem contrato".
     */
    public function up(): void
    {
        // Escola que já tem convênio Sicoob ativo continua com os boletos visíveis.
        $temConvenio = DB::table('convenios_cobranca_bancaria')->where('banco_codigo', '756')->where('ativo', 1)->exists();
        $padroes = [
            ['modo_cobranca', $temConvenio ? 'sicoob_arquivo' : 'manual', 'manual = só controle interno; sicoob_arquivo = boletos Sicoob por remessa/retorno (CNAB 400)'],
            ['financeiro_na_matricula', 'perguntar', 'perguntar = oferece gerar o contrato no cadastro do aluno e na virada; nao_perguntar = só pelas telas do financeiro'],
            ['remessa_dias_antecedencia', '15', 'Sugere para a remessa as parcelas que vencem nos próximos N dias'],
            ['instrucoes_boleto', 'Após o vencimento, cobrar multa e juros conforme contrato.', 'Texto impresso no boleto'],
        ];
        foreach ($padroes as [$chave, $valor, $descricao]) {
            DB::table('configuracoes_financeiras')->insertOrIgnore(['chave' => $chave, 'valor' => $valor, 'descricao' => $descricao]);
        }

        DB::statement("
            ALTER TABLE boletos_bancarios
                MODIFY status ENUM('gerado','enviado','registrado','pago','vencido','rejeitado','baixado','cancelado') NOT NULL DEFAULT 'gerado',
                ADD COLUMN ultima_ocorrencia VARCHAR(3) NULL AFTER status,
                ADD COLUMN ultima_ocorrencia_descricao VARCHAR(150) NULL AFTER ultima_ocorrencia,
                ADD COLUMN ultima_ocorrencia_em DATE NULL AFTER ultima_ocorrencia_descricao
        ");

        DB::statement("
            ALTER TABLE remessas_sicoob
                MODIFY status ENUM('gerado','enviado','confirmado') NOT NULL DEFAULT 'gerado',
                ADD COLUMN valor_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER quantidade_boletos,
                ADD COLUMN enviado_em DATETIME NULL AFTER status,
                ADD COLUMN enviado_por INT NULL AFTER enviado_em,
                ADD COLUMN conteudo_base64 LONGTEXT NULL AFTER enviado_por
        ");

        DB::statement("
            ALTER TABLE retornos_sicoob
                ADD COLUMN hash_arquivo CHAR(64) NULL AFTER nome_arquivo,
                ADD COLUMN valor_baixado DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER quantidade_baixados,
                ADD UNIQUE KEY uq_retorno_hash (hash_arquivo)
        ");

        DB::statement("
            CREATE TABLE retorno_sicoob_itens (
                id INT NOT NULL AUTO_INCREMENT,
                retorno_id INT NOT NULL,
                nosso_numero VARCHAR(20) NOT NULL,
                boleto_id INT NULL,
                parcela_id INT NULL,
                codigo_ocorrencia VARCHAR(3) NOT NULL,
                ocorrencia VARCHAR(150) NOT NULL,
                categoria ENUM('liquidacao','registro','rejeicao','baixa','alteracao','informativo') NOT NULL,
                resultado ENUM('aplicado','ignorado','nao_encontrado','erro') NOT NULL,
                valor_titulo DECIMAL(10,2) NULL,
                valor_pago DECIMAL(10,2) NULL,
                data_ocorrencia DATE NULL,
                mensagem VARCHAR(255) NULL,
                PRIMARY KEY (id),
                INDEX idx_rsi_retorno (retorno_id),
                INDEX idx_rsi_parcela (parcela_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement('
            CREATE TABLE plano_pagamento_turma (
                plano_id INT NOT NULL,
                turma_id INT NOT NULL,
                PRIMARY KEY (plano_id, turma_id),
                INDEX idx_ppt_turma (turma_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        DB::statement("
            CREATE TABLE financeiro_dispensas (
                id INT NOT NULL AUTO_INCREMENT,
                aluno_id INT NOT NULL,
                ano_letivo INT NOT NULL,
                motivo VARCHAR(255) NULL,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_dispensa_aluno_ano (aluno_id, ano_letivo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Remessas antigas: valor total a partir dos boletos já gravados.
        DB::statement('
            UPDATE remessas_sicoob r
            SET valor_total = (SELECT COALESCE(SUM(b.valor), 0) FROM boletos_bancarios b WHERE b.remessa_id = r.id)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS financeiro_dispensas');
        DB::statement('DROP TABLE IF EXISTS plano_pagamento_turma');
        DB::statement('DROP TABLE IF EXISTS retorno_sicoob_itens');
        DB::statement('ALTER TABLE retornos_sicoob DROP INDEX uq_retorno_hash, DROP COLUMN hash_arquivo, DROP COLUMN valor_baixado');
        DB::statement("ALTER TABLE remessas_sicoob DROP COLUMN valor_total, DROP COLUMN enviado_em, DROP COLUMN enviado_por, DROP COLUMN conteudo_base64, MODIFY status ENUM('gerado','confirmado') NOT NULL DEFAULT 'gerado'");
        DB::statement("ALTER TABLE boletos_bancarios DROP COLUMN ultima_ocorrencia, DROP COLUMN ultima_ocorrencia_descricao, DROP COLUMN ultima_ocorrencia_em, MODIFY status ENUM('gerado','enviado','pago','vencido','cancelado') NOT NULL DEFAULT 'gerado'");
        DB::table('configuracoes_financeiras')->whereIn('chave', ['modo_cobranca', 'financeiro_na_matricula', 'remessa_dias_antecedencia', 'instrucoes_boleto'])->delete();
    }
};
