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
        'diretor_nome', 'diretor_cargo', 'telefone', 'email', 'cor_primaria',
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
        // Cor do sistema (hex); '' = cor padrão. O front gera os tons a partir dela.
        'cor_primaria'    => '',
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
     * Mesmo que obter(), com logo_url já como URL absoluta — para documentos
     * impressos, que abrem numa janela própria e não conhecem o storage.
     */
    public function paraDocumento(): array
    {
        $c = $this->obter();
        $logo = (string) ($c['logo_url'] ?? '');
        if ($logo !== '' && !str_starts_with($logo, 'http')) {
            $c['logo_url'] = url('storage/' . ltrim($logo, '/'));
        }

        return $c;
    }

    /**
     * Salva só as chaves editáveis presentes em $dados (upsert por chave,
     * igual ao legado). Chaves ausentes do array não são tocadas.
     */
    public function salvar(array $dados): array
    {
        if (array_key_exists('cor_primaria', $dados)) {
            $dados['cor_primaria'] = $this->validarCor((string) $dados['cor_primaria']);
        }
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

    /**
     * Cor do sistema: hex de 6 dígitos (ou '' para a padrão). Os botões têm
     * texto branco sobre essa cor, então ela precisa de contraste de leitura
     * (WCAG AA, 4,5:1) — cor clara demais é recusada.
     * @throws \DomainException
     */
    private function validarCor(string $cor): string
    {
        $cor = strtolower(trim($cor));
        if ($cor === '') {
            return '';
        }
        if (!preg_match('/^#[0-9a-f]{6}$/', $cor)) {
            throw new \DomainException('Cor inválida.');
        }
        $lum = function (string $hex): float {
            $c = array_map(function ($i) use ($hex) {
                $v = hexdec(substr($hex, $i, 2)) / 255;
                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, [1, 3, 5]);
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        if (1.05 / ($lum($cor) + 0.05) < 4.5) {
            throw new \DomainException('Essa cor é clara demais: o texto branco dos botões fica difícil de ler. Escolha um tom mais escuro.');
        }
        return $cor;
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
