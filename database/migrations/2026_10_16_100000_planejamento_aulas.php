<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Planejamento de aulas:
     *
     * - bncc_habilidades: catálogo oficial da BNCC — habilidades do Ensino
     *   Fundamental (1º ao 9º ano) e objetivos de aprendizagem da Educação
     *   Infantil (grupos 01 a 03). Fonte: BNCC (MEC, 2018); dados estruturados
     *   por bncc.dev (mantido pela Profy), https://github.com/bncc-dev/bncc-dados,
     *   licença CC BY 4.0. Arquivo: database/data/bncc-habilidades.json.
     * - planos_aula: uma aula planejada (turma, disciplina, data), com
     *   habilidades BNCC, passo a passo, recursos e avaliação; vira registro de
     *   aula quando é dada. Aprovação da direção é opcional (parâmetro).
     * - configuracoes_escola.planejamento_aprovacao: 1 = planos passam pela
     *   aprovação da direção/coordenação; 0 (padrão) = não.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE bncc_habilidades (
                codigo VARCHAR(12) NOT NULL,
                etapa CHAR(2) NOT NULL COMMENT 'EF = Ensino Fundamental | EI = Educação Infantil',
                componente VARCHAR(80) NOT NULL COMMENT 'Componente curricular (EF) ou campo de experiência (EI)',
                ano_min TINYINT NOT NULL COMMENT 'EF: ano escolar; EI: grupo etário (1 bebês, 2 crianças bem pequenas, 3 crianças pequenas)',
                ano_max TINYINT NOT NULL,
                unidade_tematica VARCHAR(255) NULL,
                objeto_conhecimento TEXT NULL,
                texto TEXT NOT NULL,
                fonte VARCHAR(120) NULL,
                PRIMARY KEY (codigo),
                KEY idx_bncc_busca (etapa, componente, ano_min, ano_max)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $dados = json_decode(file_get_contents(database_path('data/bncc-habilidades.json')), true);
        foreach (array_chunk($dados['habilidades'], 200) as $lote) {
            DB::table('bncc_habilidades')->insert($lote);
        }

        DB::statement("
            CREATE TABLE planos_aula (
                id INT NOT NULL AUTO_INCREMENT,
                turma_id INT NOT NULL,
                disciplina_id INT NOT NULL,
                professor_id INT NULL,
                data DATE NOT NULL,
                tema VARCHAR(200) NOT NULL,
                objetivos TEXT NULL,
                habilidades JSON NULL COMMENT 'códigos BNCC (bncc_habilidades.codigo)',
                desenvolvimento TEXT NULL,
                recursos TEXT NULL,
                avaliacao TEXT NULL,
                observacoes TEXT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'planejada' COMMENT 'planejada | dada',
                aprovacao VARCHAR(12) NOT NULL DEFAULT 'nao_requer' COMMENT 'nao_requer | rascunho | pendente | aprovado | devolvido',
                comentario_aprovacao TEXT NULL,
                aprovado_por INT NULL,
                aprovado_em DATETIME NULL,
                registro_aula_id INT NULL,
                gerado_max TINYINT(1) NOT NULL DEFAULT 0,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_plano_turma_data (turma_id, data),
                KEY idx_plano_professor (professor_id, data),
                KEY idx_plano_aprovacao (aprovacao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::table('configuracoes_escola')->insertOrIgnore([
            'chave' => 'planejamento_aprovacao', 'valor' => '0',
            'descricao' => 'Planos de aula passam pela aprovação da direção/coordenação (1 = sim, 0 = não)',
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS planos_aula');
        DB::statement('DROP TABLE IF EXISTS bncc_habilidades');
        DB::table('configuracoes_escola')->where('chave', 'planejamento_aprovacao')->delete();
    }
};
