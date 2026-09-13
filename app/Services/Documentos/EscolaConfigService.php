<?php

namespace App\Services\Documentos;

use Illuminate\Support\Facades\DB;

/**
 * Equivalente a carregarConfiguracoesEscola() em api/helpers/escola_helper.php.
 * Fallback pra placeholders caso a tabela configuracoes_escola ainda não tenha
 * sido criada/preenchida no banco da escola.
 */
class EscolaConfigService
{
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
}
