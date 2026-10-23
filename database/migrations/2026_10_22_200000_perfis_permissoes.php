<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perfis novos + permissões configuráveis + acesso opcional ao portal.
     *
     * - funcoes: Coordenação Pedagógica, Secretaria e Tesouraria.
     * - perfil_permissoes: exceções da escola sobre o padrão de cada perfil
     *   (App\Support\Permissoes) — concedida 1 dá a permissão, 0 retira.
     * - usuarios.acesso_portal: aluno pode existir sem login (crianças). Quem
     *   tem só o perfil Aluno começa SEM acesso; a secretaria libera na ficha.
     *   E-mail e senha passam a ser opcionais (o UNIQUE do e-mail aceita
     *   vários NULL).
     *
     * Compatível com MySQL 5.7 e retomável (cada passo checa antes).
     */
    public function up(): void
    {
        $perfis = [
            'Coordenação Pedagógica' => 'Acompanhamento pedagógico de todas as turmas: notas, frequência, planejamentos e ocorrências.',
            'Secretaria' => 'Cadastro de alunos e responsáveis, turmas, matrículas e emissão de documentos.',
            'Tesouraria' => 'Financeiro da escola: contratos, cobranças, baixas, inadimplência e recibos.',
        ];
        foreach ($perfis as $nome => $descricao) {
            if (!DB::table('funcoes')->where('nome_funcao', $nome)->exists()) {
                DB::table('funcoes')->insert(['nome_funcao' => $nome, 'descricao' => $descricao]);
            }
        }

        if (!Schema::hasTable('perfil_permissoes')) {
            DB::statement("
                CREATE TABLE perfil_permissoes (
                    id INT NOT NULL AUTO_INCREMENT,
                    funcao_id INT NOT NULL,
                    permissao VARCHAR(60) NOT NULL,
                    concedida TINYINT(1) NOT NULL,
                    atualizado_por INT NULL,
                    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_perfil_permissao (funcao_id, permissao),
                    CONSTRAINT fk_pp_funcao FOREIGN KEY (funcao_id) REFERENCES funcoes (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasColumn('usuarios', 'acesso_portal')) {
            DB::statement("ALTER TABLE usuarios ADD COLUMN acesso_portal TINYINT(1) NOT NULL DEFAULT 1 AFTER ativo");

            // Só na criação da coluna: quem é apenas Aluno fica sem acesso.
            DB::statement("
                UPDATE usuarios u
                JOIN usuario_funcao uf ON uf.usuario_id = u.id
                JOIN funcoes f ON f.id = uf.funcao_id AND f.nome_funcao = 'Aluno'
                SET u.acesso_portal = 0
                WHERE NOT EXISTS (
                    SELECT 1 FROM usuario_funcao uf2
                    JOIN funcoes f2 ON f2.id = uf2.funcao_id
                    WHERE uf2.usuario_id = u.id AND f2.nome_funcao <> 'Aluno'
                )
            ");
        }

        DB::statement("ALTER TABLE usuarios MODIFY email VARCHAR(255) NULL");
        DB::statement("ALTER TABLE usuarios MODIFY senha VARCHAR(255) NULL");
    }

    public function down(): void
    {
        // E-mail/senha continuam opcionais: alunos sem acesso podem já ter
        // sido cadastrados sem eles.
        if (Schema::hasColumn('usuarios', 'acesso_portal')) {
            DB::statement("ALTER TABLE usuarios DROP COLUMN acesso_portal");
        }
        Schema::dropIfExists('perfil_permissoes');
        DB::table('funcoes')->whereIn('nome_funcao', ['Coordenação Pedagógica', 'Secretaria', 'Tesouraria'])->delete();
    }
};
