<?php

namespace App\Services\Financeiro\Sicoob;

use App\Services\Documentos\EscolaConfigService;
use App\Services\Financeiro\FinanceiroHelperService;
use Illuminate\Support\Facades\DB;
use OpenBoleto\Agente;
use OpenBoleto\Banco\Sicoob;

/**
 * Equivalente a api/helpers/boleto_visual_helper.php.
 *
 * Renderização visual (HTML, pronta pra impressão) do boleto Sicoob — o
 * "front" que vai pro responsável, com código de barras, linha digitável e
 * ficha de compensação. Usa openboleto/openboleto, biblioteca irmã da usada
 * na geração de CNAB, com suporte a Sicoob.
 *
 * IMPORTANTE: só gera boleto pra uma parcela que JÁ TEM um boleto_bancario
 * ativo (criado durante a geração de remessa) — o número impresso tem que
 * ser exatamente o que foi registrado no banco, nunca recalculado na hora.
 *
 * Achado durante a implementação original: essa biblioteca espera o parâmetro
 * "convenio" como código do cedente + dígito verificador CONCATENADOS (ex:
 * "1642740"), não só o código sozinho — confirmado batendo o dígito
 * verificador do nosso número contra o mesmo cálculo usado no lado do CNAB
 * (mesma fórmula "constante 3197" nas duas bibliotecas).
 */
class BoletoVisualService
{
    public function __construct(
        private ConvenioCobrancaService $convenios,
        private EscolaConfigService $escolaConfig,
        private FinanceiroHelperService $financeiro,
    ) {
    }

    /**
     * @throws \Exception se a parcela não tiver boleto ativo, o convênio não
     *         estiver cadastrado, ou o responsável não tiver os dados salvos
     */
    public function gerarHtml(int $parcelaId): string
    {
        $boleto = DB::table('boletos_bancarios as bb')
            ->join('parcelas as p', 'p.id', '=', 'bb.parcela_id')
            ->where('bb.parcela_id', $parcelaId)
            ->whereIn('bb.status', ['gerado', 'enviado'])
            ->orderByDesc('bb.id')
            ->first(['bb.nosso_numero', 'bb.valor', 'bb.data_vencimento', 'bb.data_emissao', 'p.aluno_id']);

        if (!$boleto) {
            throw new \Exception('Essa parcela não tem boleto ativo — gere a remessa Sicoob primeiro.');
        }

        $convenio = $this->convenios->carregar('756');
        if (!$convenio || !$convenio['ativo']) {
            throw new \Exception('Convênio bancário do Sicoob não está cadastrado/ativo.');
        }

        $escola = $this->escolaConfig->obter();

        $responsavel = $this->financeiro->buscarResponsavelPagador((int) $boleto->aluno_id);
        if (!$responsavel) {
            throw new \Exception('Responsável do aluno não encontrado.');
        }
        $camposEndereco = ['cpf', 'endereco', 'bairro', 'cidade', 'uf', 'cep'];
        $faltando = array_filter($camposEndereco, fn ($c) => empty($responsavel[$c]));
        if ($faltando) {
            throw new \Exception('Responsável sem ' . implode(', ', $faltando) . ' cadastrado — obrigatório pra imprimir o boleto.');
        }

        $sacado = new Agente(
            $responsavel['nome_completo'],
            $responsavel['cpf'],
            trim($responsavel['endereco'] . ', ' . $responsavel['bairro'], ', '),
            $responsavel['cep'],
            $responsavel['cidade'],
            $responsavel['uf']
        );

        $cedente = new Agente(
            $escola['nome_escola'],
            $escola['cnpj'],
            $escola['endereco'] ?: '',
            '',
            '',
            ''
        );

        $cedenteDv = $convenio['codigo_cedente_dv'] ?: '0';
        $agenciaDv = $convenio['agencia_dv'] ?: null;

        $boletoObj = new Sicoob([
            'dataVencimento' => new \DateTime($boleto->data_vencimento),
            'dataDocumento' => new \DateTime($boleto->data_emissao),
            'valor' => (float) $boleto->valor,
            'sequencial' => (int) $boleto->nosso_numero,
            'sacado' => $sacado,
            'cedente' => $cedente,
            'agencia' => $convenio['agencia'],
            'agenciaDv' => $agenciaDv,
            'carteira' => $convenio['carteira'],
            // ver nota no topo do arquivo — concatenar código do cedente + DV é
            // proposital, não um erro.
            'convenio' => $convenio['codigo_cedente'] . $cedenteDv,
            'especieDoc' => 'DM',
            'aceite' => false,
            'instrucoes' => ['Não receber após o vencimento sem autorização da secretaria.'],
        ]);

        return $boletoObj->getOutput();
    }
}
