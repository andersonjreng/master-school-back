<?php

namespace App\Services\Rematricula;

use App\Services\Documentos\EscolaConfigService;
use App\Services\Financeiro\FinanceiroConfigService;

/**
 * Texto do contrato da rematrícula.
 *
 * A escola edita um modelo em texto simples: parágrafos separados por linha
 * em branco, "# " no começo da linha vira título e **assim** vira negrito.
 * Campos entre chaves ({aluno}, {valor_parcela}...) são preenchidos com os
 * dados de cada aluno. O HTML gerado é o que a família lê, o que vai para o
 * hash do aceite e a cópia guardada — por isso a montagem é determinística.
 */
class ContratoRematriculaService
{
    /** Campos disponíveis no modelo (chave => explicação para a tela). */
    public const CAMPOS = [
        'escola'               => 'Nome da escola',
        'escola_cnpj'          => 'CNPJ da escola',
        'escola_endereco'      => 'Endereço da escola',
        'responsavel'          => 'Nome do responsável financeiro',
        'responsavel_cpf'      => 'CPF do responsável',
        'responsavel_endereco' => 'Endereço do responsável',
        'aluno'                => 'Nome do aluno',
        'aluno_nascimento'     => 'Data de nascimento do aluno',
        'turma'                => 'Turma do ano novo',
        'ano'                  => 'Ano letivo do contrato',
        'plano'                => 'Nome do plano escolhido',
        'valor_total'          => 'Valor total do ano (com desconto)',
        'parcelas'             => 'Quantidade de parcelas',
        'valor_parcela'        => 'Valor de cada parcela (com desconto)',
        'dia_vencimento'       => 'Dia de vencimento das parcelas',
        'desconto'             => 'Desconto aplicado (ou "sem desconto")',
        'pagamento_inicial'    => 'O que é pago na rematrícula e em quanto tempo',
        'multa'                => 'Multa por atraso (%)',
        'juros'                => 'Juros por dia de atraso (%)',
        'cidade'               => 'Cidade da escola (do endereço)',
        'data'                 => 'Data do aceite, por extenso',
    ];

    public function __construct(
        private EscolaConfigService $escola,
        private FinanceiroConfigService $financeiro,
    ) {
    }

    public function modeloPadrao(): string
    {
        return <<<'TXT'
# CONTRATO DE PRESTAÇÃO DE SERVIÇOS EDUCACIONAIS — ANO LETIVO {ano}

De um lado, **{escola}**, inscrita no CNPJ sob o nº {escola_cnpj}, com sede em {escola_endereco}, doravante denominada **ESCOLA**; de outro, **{responsavel}**, CPF nº {responsavel_cpf}, residente em {responsavel_endereco}, doravante denominado(a) **CONTRATANTE**, responsável financeiro pelo(a) aluno(a) **{aluno}**, nascido(a) em {aluno_nascimento}, celebram o presente contrato, nos termos da Lei nº 9.870/1999 e do Código de Defesa do Consumidor.

# 1. Objeto

A ESCOLA prestará ao(à) aluno(a) os serviços educacionais correspondentes à turma **{turma}**, no ano letivo de **{ano}**, conforme o calendário escolar, a proposta pedagógica e o regimento interno da ESCOLA, que o CONTRATANTE declara conhecer.

# 2. Valor e forma de pagamento

Pelos serviços do ano letivo, o CONTRATANTE pagará o valor total de **{valor_total}**, pelo plano **{plano}**, em **{parcelas} parcelas de {valor_parcela}**, com vencimento todo dia **{dia_vencimento}**. Desconto concedido: {desconto}.

{pagamento_inicial}

Os pagamentos serão feitos por boleto bancário com Pix ou diretamente na secretaria da ESCOLA.

# 3. Atraso no pagamento

O pagamento após o vencimento terá multa de {multa}% e juros de {juros}% ao dia sobre o valor em atraso. O atraso não autoriza a suspensão das provas, a retenção de documentos escolares nem qualquer medida vexatória contra o(a) aluno(a), nos termos da lei.

# 4. Rematrícula e vaga

A vaga do(a) aluno(a) para o ano letivo de {ano} fica garantida com o aceite deste contrato e a confirmação do pagamento inicial, quando houver. A ESCOLA pode recusar a rematrícula de aluno com débitos de anos anteriores, conforme o art. 5º da Lei nº 9.870/1999.

# 5. Desistência e transferência

O CONTRATANTE pode desistir ou pedir a transferência do(a) aluno(a) a qualquer tempo, por comunicação à secretaria. São devidas as parcelas vencidas até a data do pedido; as parcelas a vencer depois dele são canceladas.

# 6. Obrigações do CONTRATANTE

Manter atualizados os dados de contato e de endereço, acompanhar a vida escolar do(a) aluno(a) e zelar pelo cumprimento do regimento interno da ESCOLA.

# 7. Dados pessoais

A ESCOLA trata os dados pessoais do CONTRATANTE e do(a) aluno(a) para a prestação dos serviços educacionais, para o cumprimento de obrigações legais e para a comunicação com a família, conforme a Lei nº 13.709/2018 (LGPD).

# 8. Aceite eletrônico

Este contrato é aceito por meio eletrônico, no sistema da ESCOLA, com registro de data, hora, endereço IP e aparelho utilizados, o que as partes reconhecem como forma válida de manifestação de vontade (Lei nº 14.063/2020 e art. 10, § 2º, da MP nº 2.200-2/2001).

# 9. Foro

Fica eleito o foro da comarca de {cidade} para dirimir questões oriundas deste contrato.

{cidade}, {data}.
TXT;
    }

    /**
     * Valores dos campos para um aluno/plano. $campanha e $plano são os
     * registros do banco; $valores traz o que já foi calculado (desconto etc.).
     *
     * @param array{responsavel: array, aluno: object, turma: string, plano: object, valor_parcela: float,
     *              valor_total: float, desconto: string, data: \DateTimeInterface} $d
     */
    public function campos(object $campanha, array $d): array
    {
        $escola = $this->escola->obter();
        $fin = $this->financeiro->todas();
        $r = $d['responsavel'];

        $endereco = trim(implode(', ', array_filter([
            $r['endereco'] ?? null, $r['numero'] ?? null, $r['complemento'] ?? null, $r['bairro'] ?? null,
        ])) . (!empty($r['cidade']) ? " – {$r['cidade']}/" . ($r['uf'] ?? '') : '') . (!empty($r['cep']) ? " – CEP {$r['cep']}" : ''));

        return [
            'escola'               => $escola['nome_escola'] ?? '',
            'escola_cnpj'          => $escola['cnpj'] ?? '',
            'escola_endereco'      => $escola['endereco'] ?? '',
            'responsavel'          => $r['nome'] ?? '',
            'responsavel_cpf'      => $r['cpf'] ?? '',
            'responsavel_endereco' => $endereco !== '' ? $endereco : 'endereço não informado',
            'aluno'                => $d['aluno']->nome ?? '',
            'aluno_nascimento'     => !empty($d['aluno']->data_nascimento) ? date('d/m/Y', strtotime($d['aluno']->data_nascimento)) : 'não informada',
            'turma'                => $d['turma'],
            'ano'                  => (string) $campanha->ano_destino,
            'plano'                => $d['plano']->nome,
            'valor_total'          => $this->moeda($d['valor_total']),
            'parcelas'             => (string) (int) $d['plano']->numero_parcelas,
            'valor_parcela'        => $this->moeda($d['valor_parcela']),
            'dia_vencimento'       => (string) (int) $d['plano']->dia_vencimento,
            'desconto'             => $d['desconto'],
            'pagamento_inicial'    => $this->pagamentoInicial($campanha, $d['valor_parcela']),
            'multa'                => $this->numero((float) ($fin['multa_percentual'] ?? 2)),
            'juros'                => $this->numero((float) ($fin['juros_diario_percentual'] ?? 0.033)),
            'cidade'               => $this->cidadeDaEscola($escola['endereco'] ?? ''),
            'data'                 => $this->dataPorExtenso($d['data']),
        ];
    }

    /** Monta o HTML do contrato a partir do modelo (texto simples) e dos campos. */
    public function montar(string $modelo, array $campos): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", trim($modelo));
        $blocos = preg_split("/\n\s*\n/", $texto) ?: [];
        $html = [];
        foreach ($blocos as $bloco) {
            $bloco = trim($bloco);
            if ($bloco === '') {
                continue;
            }
            $titulo = str_starts_with($bloco, '# ');
            if ($titulo) {
                $bloco = substr($bloco, 2);
            }
            $conteudo = $this->formatar($bloco, $campos);
            if ($conteudo === '') {
                continue;
            }
            $html[] = $titulo ? "<h3>$conteudo</h3>" : "<p>$conteudo</p>";
        }
        return implode("\n", $html);
    }

    public function hash(string $html): string
    {
        return hash('sha256', $html);
    }

    /** Campos usados no modelo que não existem (para avisar a escola ao salvar). */
    public function camposDesconhecidos(string $modelo): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $modelo, $m);
        return array_values(array_unique(array_diff($m[1], array_keys(self::CAMPOS))));
    }

    private function formatar(string $bloco, array $campos): string
    {
        // Escapa primeiro: o texto da escola nunca vira HTML/JS; só ** vira negrito.
        $seguro = htmlspecialchars($bloco, ENT_QUOTES, 'UTF-8');
        $seguro = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($campos) {
            return array_key_exists($m[1], $campos) ? htmlspecialchars((string) $campos[$m[1]], ENT_QUOTES, 'UTF-8') : $m[0];
        }, $seguro);
        $seguro = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $seguro);
        return nl2br(trim($seguro), false);
    }

    private function pagamentoInicial(object $campanha, float $valorParcela): string
    {
        $prazo = (int) $campanha->prazo_pagamento_dias;
        $dias = $prazo === 1 ? '1 dia' : "$prazo dias";
        return match ($campanha->cobranca) {
            'taxa' => 'Na rematrícula é cobrada a taxa de matrícula de ' . $this->moeda((float) $campanha->taxa_valor)
                . ", com vencimento em $dias após o aceite, além das parcelas acima.",
            'primeira_parcela' => 'A 1ª parcela (' . $this->moeda($valorParcela) . ") vence em $dias após o aceite deste contrato; as demais seguem o vencimento acima.",
            default => 'Não há pagamento no ato da rematrícula; as parcelas seguem o vencimento acima.',
        };
    }

    private function cidadeDaEscola(string $endereco): string
    {
        // "Av. X, 61 – Bairro – Natividade – RJ – CEP: ..." → "Natividade/RJ"
        $partes = array_values(array_filter(array_map('trim', preg_split('/\s+[–-]\s+/u', $endereco) ?: [])));
        foreach ($partes as $i => $p) {
            if (preg_match('/^[A-Z]{2}$/', $p) && $i > 0) {
                return $partes[$i - 1] . '/' . $p;
            }
        }
        return 'sede da escola';
    }

    private function dataPorExtenso(\DateTimeInterface $d): string
    {
        $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return $d->format('j') . ' de ' . $meses[(int) $d->format('n') - 1] . ' de ' . $d->format('Y');
    }

    private function moeda(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    private function numero(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    }
}
