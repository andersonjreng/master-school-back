<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cadastros completos — base para boleto, WhatsApp, documentos e futuras
     * integrações:
     *
     * - alunos: CPF (opcional, único), sexo, endereço estruturado (CEP,
     *   número, complemento, bairro, cidade, UF) e observações de saúde.
     * - responsaveis: número e complemento (o boleto junta tudo numa linha).
     * - professores: CPF (único) e data de nascimento. O telefone de professor,
     *   aluno e usuário fica em usuarios.telefone (já existia, mas não era salvo).
     * - Normaliza telefone e CEP já gravados para só dígitos.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE alunos
                ADD COLUMN cpf VARCHAR(14) NULL AFTER matricula,
                ADD COLUMN sexo ENUM('F','M','O') NULL AFTER data_nascimento,
                ADD COLUMN cep VARCHAR(9) NULL AFTER endereco,
                ADD COLUMN numero VARCHAR(20) NULL AFTER cep,
                ADD COLUMN complemento VARCHAR(100) NULL AFTER numero,
                ADD COLUMN bairro VARCHAR(100) NULL AFTER complemento,
                ADD COLUMN cidade VARCHAR(100) NULL AFTER bairro,
                ADD COLUMN uf CHAR(2) NULL AFTER cidade,
                ADD COLUMN observacoes_saude TEXT NULL AFTER uf,
                ADD UNIQUE KEY uq_alunos_cpf (cpf)
        ");

        DB::statement("
            ALTER TABLE responsaveis
                ADD COLUMN numero VARCHAR(20) NULL AFTER endereco,
                ADD COLUMN complemento VARCHAR(100) NULL AFTER numero
        ");

        DB::statement("
            ALTER TABLE professores
                ADD COLUMN cpf VARCHAR(14) NULL AFTER usuario_id,
                ADD COLUMN data_nascimento DATE NULL AFTER cpf,
                ADD UNIQUE KEY uq_professores_cpf (cpf)
        ");

        DB::statement("UPDATE responsaveis SET telefone = NULLIF(REGEXP_REPLACE(telefone, '[^0-9]', ''), ''), cep = NULLIF(REGEXP_REPLACE(cep, '[^0-9]', ''), '')");
        DB::statement("UPDATE usuarios SET telefone = NULLIF(REGEXP_REPLACE(telefone, '[^0-9]', ''), '') WHERE telefone IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE alunos DROP INDEX uq_alunos_cpf, DROP COLUMN cpf, DROP COLUMN sexo, DROP COLUMN cep, DROP COLUMN numero,
            DROP COLUMN complemento, DROP COLUMN bairro, DROP COLUMN cidade, DROP COLUMN uf, DROP COLUMN observacoes_saude');
        DB::statement('ALTER TABLE responsaveis DROP COLUMN numero, DROP COLUMN complemento');
        DB::statement('ALTER TABLE professores DROP INDEX uq_professores_cpf, DROP COLUMN cpf, DROP COLUMN data_nascimento');
    }
};
