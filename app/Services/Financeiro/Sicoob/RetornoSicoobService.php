<?php

namespace App\Services\Financeiro\Sicoob;

use App\Services\Financeiro\FinanceiroHelperService;
use CnabPHP\Retorno;
use CnabPHP\RetornoAbstract;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/helpers/retorno_sicoob_helper.php.
 *
 * Leitura de arquivo de retorno CNAB400 do Sicoob (banco 756) e baixa
 * automática das parcelas pagas. Cenário A: o arquivo é baixado manualmente
 * pelo usuário no site do Sicoob e importado aqui — não há recebimento
 * automático via webhook (Cenário B, não implementado).
 *
 * Só o código de ocorrência "06" (liquidação normal) aplica baixa automática.
 * Outros códigos (ex: "09" baixado automaticamente, "10" baixa solicitada)
 * não significam necessariamente pagamento — ficam separados pra revisão
 * manual em vez de arriscar dar baixa em algo que não foi pago.
 */
class RetornoSicoobService
{
    private const CODIGO_MOVIMENTO_LIQUIDACAO_SICOOB = 6;

    public function __construct(
        private ConvenioCobrancaService $convenios,
        private FinanceiroHelperService $financeiro,
    ) {
    }

    /**
     * @return array{
     *   pagos: array<array{parcela_id:int, nosso_numero:string, valor:float, baixa_id:int, numero_recibo:string}>,
     *   revisar: array<array{nosso_numero:string, codigo_movimento:int, motivo:string}>,
     *   erros: array<array{nosso_numero:string, motivo:string}>,
     *   retorno_id: int,
     * }
     * @throws \Exception se o arquivo não puder ser lido, não for do Sicoob, ou o convênio não existir
     */
    public function processar(string $conteudoArquivo, string $nomeArquivo, int $usuarioId): array
    {
        try {
            $arquivo = new Retorno($conteudoArquivo);
        } catch (\Throwable $e) {
            throw new \Exception('Não foi possível ler o arquivo — verifique se é mesmo um retorno CNAB do Sicoob. (' . $e->getMessage() . ')');
        }

        $banco = (int) RetornoAbstract::$banco;
        if ($banco !== 756) {
            throw new \Exception("Arquivo não é do Sicoob (banco 756) — banco detectado no cabeçalho: $banco.");
        }

        $convenio = $this->convenios->carregar('756');
        if (!$convenio) {
            throw new \Exception('Convênio bancário do Sicoob não está cadastrado.');
        }

        $registros = $arquivo->getRegistros();

        // Cria o registro do retorno já no início (com contadores zerados) pra
        // poder referenciar retorno_id em cada baixa aplicada no loop abaixo;
        // os contadores de verdade são atualizados no final.
        $retornoId = DB::table('retornos_sicoob')->insertGetId([
            'convenio_id' => $convenio['id'],
            'nome_arquivo' => $nomeArquivo,
            'quantidade_registros' => count($registros),
            'processado_por' => $usuarioId,
        ]);

        $pagos = [];
        $revisar = [];
        $erros = [];

        foreach ($registros as $registro) {
            $nossoNumero = (string) (int) $registro->nosso_numero;
            $codigoMovimento = (int) $registro->codigo_movimento;

            if ($codigoMovimento !== self::CODIGO_MOVIMENTO_LIQUIDACAO_SICOOB) {
                $revisar[] = [
                    'nosso_numero' => $nossoNumero,
                    'codigo_movimento' => $codigoMovimento,
                    'motivo' => "Código de ocorrência $codigoMovimento não é liquidação — não foi dado baixa automaticamente. Confira manualmente.",
                ];
                continue;
            }

            $boleto = DB::table('boletos_bancarios')
                ->where('convenio_id', $convenio['id'])
                ->where('nosso_numero', $nossoNumero)
                ->whereIn('status', ['gerado', 'enviado'])
                ->first(['id', 'parcela_id']);

            if (!$boleto) {
                $erros[] = [
                    'nosso_numero' => $nossoNumero,
                    'motivo' => 'Nenhum boleto ativo encontrado com esse nosso número — pode já ter sido baixado, ou não foi gerado por este sistema.',
                ];
                continue;
            }

            $valorPago = (float) $registro->vlr_pago;
            $dataOcorrencia = (string) $registro->data_ocorrencia; // já vem como 'Y-m-d'

            try {
                $resultado = $this->financeiro->aplicarBaixaParcela(
                    (int) $boleto->parcela_id,
                    $valorPago,
                    $dataOcorrencia ?: now()->toDateString(),
                    'boleto',
                    "Baixa automática via retorno Sicoob (nosso número $nossoNumero)",
                    $usuarioId,
                    (int) $boleto->id,
                    $retornoId
                );

                DB::table('boletos_bancarios')->where('id', $boleto->id)->update(['status' => 'pago']);

                $pagos[] = [
                    'parcela_id' => (int) $boleto->parcela_id,
                    'nosso_numero' => $nossoNumero,
                    'valor' => $valorPago,
                    'baixa_id' => $resultado['baixa_id'],
                    'numero_recibo' => $resultado['numero_recibo'],
                ];
            } catch (\Exception $e) {
                $erros[] = ['nosso_numero' => $nossoNumero, 'motivo' => $e->getMessage()];
            }
        }

        DB::table('retornos_sicoob')->where('id', $retornoId)->update([
            'quantidade_baixados' => count($pagos),
            'observacoes' => count($erros) . ' erro(s), ' . count($revisar) . ' para revisão manual.',
        ]);

        return ['pagos' => $pagos, 'revisar' => $revisar, 'erros' => $erros, 'retorno_id' => $retornoId];
    }
}
