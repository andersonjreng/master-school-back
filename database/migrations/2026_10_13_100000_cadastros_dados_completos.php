<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     *
     * Compatível com MySQL 5.7 (servidor) — sem REGEXP_REPLACE, que só existe
     * no MySQL 8 / MariaDB: a normalização é feita em PHP. E retomável: cada
     * ALTER só roda se a coluna ainda não existe (MySQL não desfaz DDL, então
     * uma execução que falhou no meio deixava as colunas criadas e a migration
     * pendente — foi o que aconteceu no banco teste em 2026-10-20).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('alunos', 'cpf')) {
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
        }

        if (!Schema::hasColumn('responsaveis', 'numero')) {
            DB::statement("
                ALTER TABLE responsaveis
                    ADD COLUMN numero VARCHAR(20) NULL AFTER endereco,
                    ADD COLUMN complemento VARCHAR(100) NULL AFTER numero
            ");
        }

        if (!Schema::hasColumn('professores', 'cpf')) {
            DB::statement("
                ALTER TABLE professores
                    ADD COLUMN cpf VARCHAR(14) NULL AFTER usuario_id,
                    ADD COLUMN data_nascimento DATE NULL AFTER cpf,
                    ADD UNIQUE KEY uq_professores_cpf (cpf)
            ");
        }

        $digitos = fn ($v) => $v === null ? null : (preg_replace('/\D/', '', (string) $v) ?: null);

        foreach (DB::table('responsaveis')->get(['id', 'telefone', 'cep']) as $r) {
            $tel = $digitos($r->telefone);
            $cep = $digitos($r->cep);
            if ($tel !== $r->telefone || $cep !== $r->cep) {
                DB::table('responsaveis')->where('id', $r->id)->update(['telefone' => $tel, 'cep' => $cep]);
            }
        }
        foreach (DB::table('usuarios')->whereNotNull('telefone')->get(['id', 'telefone']) as $u) {
            $tel = $digitos($u->telefone);
            if ($tel !== $u->telefone) {
                DB::table('usuarios')->where('id', $u->id)->update(['telefone' => $tel]);
            }
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE alunos DROP INDEX uq_alunos_cpf, DROP COLUMN cpf, DROP COLUMN sexo, DROP COLUMN cep, DROP COLUMN numero,
            DROP COLUMN complemento, DROP COLUMN bairro, DROP COLUMN cidade, DROP COLUMN uf, DROP COLUMN observacoes_saude');
        DB::statement('ALTER TABLE responsaveis DROP COLUMN numero, DROP COLUMN complemento');
        DB::statement('ALTER TABLE professores DROP INDEX uq_professores_cpf, DROP COLUMN cpf, DROP COLUMN data_nascimento');
    }
};
