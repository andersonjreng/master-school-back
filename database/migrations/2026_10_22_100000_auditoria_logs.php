<?php

use App\Services\Sistema\AuditoriaCatalogo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoria completa em logs_sistema (tela "Logs do Sistema").
     *
     * - categoria: acesso, navegacao, exportacao, cadastro, academico,
     *   financeiro, comunicacao, sistema (ver AuditoriaCatalogo). Linhas
     *   antigas são classificadas aqui mesmo, pela ação.
     * - sessao_id: claim "sid" do JWT, gerado em cada login — junta login,
     *   telas visitadas, ações e logout de uma mesma sessão.
     * - tela: rota do front onde o usuário estava (header X-Tela).
     * - user_agent: navegador/dispositivo.
     *
     * Compatível com MySQL 5.7 e retomável: cada coluna/índice só é criado se
     * ainda não existir (MySQL não desfaz DDL de migration que quebra no meio).
     */
    public function up(): void
    {
        if (!Schema::hasTable('logs_sistema')) {
            DB::statement("
                CREATE TABLE logs_sistema (
                    id INT NOT NULL AUTO_INCREMENT,
                    usuario_id INT NULL,
                    username VARCHAR(100) NULL,
                    acao VARCHAR(100) NOT NULL,
                    endpoint VARCHAR(255) NOT NULL,
                    metodo VARCHAR(10) NOT NULL,
                    descricao TEXT NULL,
                    ip_address VARCHAR(45) NULL,
                    status_code SMALLINT NULL,
                    dados_extras JSON NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    INDEX idx_usuario_id (usuario_id),
                    INDEX idx_acao (acao),
                    INDEX idx_criado_em (criado_em)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $colunas = [
            'categoria'  => "VARCHAR(30) NULL AFTER acao",
            'sessao_id'  => "VARCHAR(40) NULL AFTER categoria",
            'tela'       => "VARCHAR(255) NULL AFTER endpoint",
            'user_agent' => "VARCHAR(255) NULL AFTER ip_address",
        ];
        foreach ($colunas as $coluna => $definicao) {
            if (!Schema::hasColumn('logs_sistema', $coluna)) {
                DB::statement("ALTER TABLE logs_sistema ADD COLUMN $coluna $definicao");
            }
        }

        $indices = [
            'idx_logs_categoria_data' => '(categoria, criado_em)',
            'idx_logs_usuario_data'   => '(usuario_id, criado_em)',
            'idx_logs_sessao'         => '(sessao_id)',
            'idx_logs_ip'             => '(ip_address)',
        ];
        foreach ($indices as $nome => $colunasIndice) {
            if (!$this->temIndice($nome)) {
                DB::statement("ALTER TABLE logs_sistema ADD INDEX $nome $colunasIndice");
            }
        }

        // Classifica o histórico pela ação (regra em PHP, igual à da gravação).
        $acoes = DB::table('logs_sistema')->whereNull('categoria')->distinct()->pluck('acao');
        foreach ($acoes as $acao) {
            DB::table('logs_sistema')->whereNull('categoria')->where('acao', $acao)
                ->update(['categoria' => AuditoriaCatalogo::categoriaDaAcao((string) $acao)]);
        }
    }

    public function down(): void
    {
        foreach (['idx_logs_categoria_data', 'idx_logs_usuario_data', 'idx_logs_sessao', 'idx_logs_ip'] as $nome) {
            if ($this->temIndice($nome)) {
                DB::statement("ALTER TABLE logs_sistema DROP INDEX $nome");
            }
        }
        foreach (['categoria', 'sessao_id', 'tela', 'user_agent'] as $coluna) {
            if (Schema::hasColumn('logs_sistema', $coluna)) {
                DB::statement("ALTER TABLE logs_sistema DROP COLUMN $coluna");
            }
        }
    }

    private function temIndice(string $nome): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'logs_sistema')
            ->where('index_name', $nome)
            ->exists();
    }
};
