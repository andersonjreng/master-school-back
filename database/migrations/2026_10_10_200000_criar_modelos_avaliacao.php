<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Modelos de avaliação (Configurações → Parametrizações).
     *
     * A média bimestral é Σ (nota / valor_máximo) × peso / 10 — só fecha em 10
     * quando os pesos das avaliações da disciplina no bimestre somam 100. Por
     * isso o que se parametriza é um MODELO: um grupo de tipos cujos pesos
     * somam exatamente 100 (= 10 pontos). Ex.: "Padrão bimestral" = Teste 30 +
     * Prova 50 + Trabalho 20; "Avaliação única" = Única 100.
     *
     * Cada tipo pertence a um modelo; cada modelo vale para todas as turmas ou
     * para turmas selecionadas. Numa disciplina/bimestre, todas as avaliações
     * devem vir do mesmo modelo (validado no cadastro de avaliações).
     */
    public function up(): void
    {
        DB::statement('
            CREATE TABLE modelos_avaliacao (
                id INT NOT NULL AUTO_INCREMENT,
                nome VARCHAR(100) NOT NULL,
                descricao VARCHAR(255) NULL,
                padrao TINYINT(1) NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                todas_turmas TINYINT(1) NOT NULL DEFAULT 1,
                criado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        DB::statement('
            CREATE TABLE modelo_avaliacao_turma (
                modelo_avaliacao_id INT NOT NULL,
                turma_id INT NOT NULL,
                PRIMARY KEY (modelo_avaliacao_id, turma_id),
                INDEX idx_mat_turma (turma_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        DB::statement('
            ALTER TABLE tipos_avaliacao
                ADD COLUMN modelo_avaliacao_id INT NULL AFTER id,
                ADD COLUMN cor VARCHAR(7) NULL AFTER soma_maxima,
                ADD COLUMN ordem INT NOT NULL DEFAULT 0 AFTER cor,
                ADD INDEX idx_tipo_modelo (modelo_avaliacao_id)
        ');

        // Cores que o front já usava por nome.
        $cores = ['prova' => '#3b82f6', 'teste' => '#34d399', 'trabalho' => '#06b6d4', 'nica' => '#f59e0b'];
        $tipos = DB::table('tipos_avaliacao')->orderBy('id')->get(['id', 'nome_tipo', 'peso']);
        foreach ($tipos as $tipo) {
            $nome = mb_strtolower($tipo->nome_tipo);
            foreach ($cores as $chave => $cor) {
                if (str_contains($nome, $chave)) {
                    DB::table('tipos_avaliacao')->where('id', $tipo->id)->update(['cor' => $cor]);
                    break;
                }
            }
        }

        // Tipos com peso 100 viram, cada um, um modelo próprio (ex.: Avaliação
        // única). Os demais formam o "Padrão bimestral".
        $compostos = $tipos->filter(fn ($t) => (int) $t->peso < 100)->sortByDesc('peso')->values();
        if ($compostos->isNotEmpty()) {
            $modeloId = DB::table('modelos_avaliacao')->insertGetId([
                'nome' => 'Padrão bimestral', 'padrao' => 1, 'ativo' => 1, 'todas_turmas' => 1,
                'descricao' => 'Composição usada pela escola até a parametrização.',
            ]);
            foreach ($compostos as $i => $t) {
                DB::table('tipos_avaliacao')->where('id', $t->id)->update(['modelo_avaliacao_id' => $modeloId, 'ordem' => $i]);
            }
        }
        foreach ($tipos->filter(fn ($t) => (int) $t->peso >= 100) as $t) {
            $modeloId = DB::table('modelos_avaliacao')->insertGetId([
                'nome' => $t->nome_tipo, 'padrao' => 1, 'ativo' => 1, 'todas_turmas' => 1,
                'descricao' => 'Uma única avaliação vale a nota inteira do bimestre.',
            ]);
            DB::table('tipos_avaliacao')->where('id', $t->id)->update(['modelo_avaliacao_id' => $modeloId, 'ordem' => 0]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tipos_avaliacao DROP INDEX idx_tipo_modelo, DROP COLUMN modelo_avaliacao_id, DROP COLUMN cor, DROP COLUMN ordem');
        DB::statement('DROP TABLE IF EXISTS modelo_avaliacao_turma');
        DB::statement('DROP TABLE IF EXISTS modelos_avaliacao');
    }
};
