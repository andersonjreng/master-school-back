<?php

namespace App\Services\Documentos;

use Illuminate\Support\Facades\DB;

/**
 * Equivalente a carregarConfiguracoesEscola() (leitura) e ao INSERT ... ON
 * DUPLICATE KEY UPDATE de api/escola/post_configuracoes_escola.php (escrita)
 * em api/helpers/escola_helper.php. Fallback pra placeholders caso a tabela
 * configuracoes_escola ainda não tenha sido criada/preenchida no banco da
 * escola.
 */
class EscolaConfigService
{
    /** logo_url fica de fora: só é alterado via ConfigController::setLogo()/deleteLogo(). */
    private const CHAVES_EDITAVEIS = [
        'nome_escola', 'cnpj', 'endereco', 'autorizacao_mec',
        'diretor_nome', 'diretor_cargo', 'telefone', 'email',
    ];

    private const DEFAULTS = [
        'nome_escola'     => 'Centro Educacional Perlingeiro La Cava — Jardim Escola Sonho de Criança',
        'cnpj'            => '59.295.316/0001-09',
        'endereco'        => 'Av. Antônio da Silva Campos, 61 – Morada do Engenho – Natividade – RJ – CEP: 28380-000',
        'autorizacao_mec' => 'Autorizado pelo MEC – Port. E/AS/AUT nº 71 de 06/10/2004',
        'diretor_nome'    => 'Nome do(a) Diretor(a)',
        'diretor_cargo'   => 'Diretor(a) Escolar',
        'telefone'        => '',
        'email'           => '',
        'logo_url'        => '',
    ];

    public function obter(): array
    {
        $config = self::DEFAULTS;

        try {
            foreach (DB::table('configuracoes_escola')->get(['chave', 'valor']) as $row) {
                if (array_key_exists($row->chave, $config)) {
                    $config[$row->chave] = $row->valor;
                }
            }
        } catch (\Throwable) {
            // Tabela pode não existir ainda em algum tenant — segue com os defaults.
        }

        return $config;
    }

    /**
     * Salva só as chaves editáveis presentes em $dados (upsert por chave,
     * igual ao legado). Chaves ausentes do array não são tocadas.
     */
    public function salvar(array $dados): array
    {
        foreach (self::CHAVES_EDITAVEIS as $chave) {
            if (!array_key_exists($chave, $dados)) {
                continue;
            }

            DB::table('configuracoes_escola')->updateOrInsert(
                ['chave' => $chave],
                ['valor' => (string) $dados[$chave]]
            );
        }

        return $this->obter();
    }

    public function definirLogo(string $caminhoBanco): void
    {
        DB::table('configuracoes_escola')->updateOrInsert(
            ['chave' => 'logo_url'],
            ['valor' => $caminhoBanco]
        );
    }

    public function logoAtual(): ?string
    {
        $valor = DB::table('configuracoes_escola')->where('chave', 'logo_url')->value('valor');

        return $valor ?: null;
    }

    /** Coluna valor é NOT NULL — string vazia é o sentinel de "sem logo". */
    public function removerLogo(): void
    {
        DB::table('configuracoes_escola')->where('chave', 'logo_url')->update(['valor' => '']);
    }
}
