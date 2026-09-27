<?php

namespace App\Services\Financeiro\Sicoob;

use App\Services\Documentos\EscolaConfigService;
use App\Services\Financeiro\FinanceiroHelperService;
use CnabPHP\Remessa;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/helpers/remessa_sicoob_helper.php.
 *
 * Geração de arquivo de remessa CNAB400 pro Sicoob (banco 756), via
 * openboleto/opencnabphp. Cenário A: o arquivo é baixado aqui e sobe
 * manualmente no site do Sicoob — não há envio automático (Cenário B, não
 * implementado).
 *
 * Formato confirmado CNAB400 (não 240) a partir de um arquivo de retorno real
 * da escola. Vários campos do "detalhe" (agência, conta, código/dígito do
 * cedente, CNPJ da empresa) são, por design da biblioteca, ignorados no array
 * de cada boleto — ela sempre busca esses valores no header, então não
 * adianta repeti-los por parcela.
 */
class RemessaSicoobService
{
    public function __construct(
        private ConvenioCobrancaService $convenios,
        private EscolaConfigService $escolaConfig,
        private FinanceiroHelperService $financeiro,
        private CnabEncodingService $cnab,
    ) {
    }

    /**
     * Gera a remessa para uma lista de parcelas. Parcelas inválidas (já pagas,
     * já com boleto ativo, ou cujo responsável não tem endereço completo
     * cadastrado) são puladas e reportadas em vez de travar o lote inteiro.
     *
     * @return array{
     *   conteudo: string,
     *   nome_arquivo: string,
     *   quantidade: int,
     *   avisos: string[],
     *   puladas: array<array{parcela_id:int, motivo:string}>,
     *   remessa_id: int,
     * }
     * @throws \Exception se não houver convênio cadastrado, ou nenhuma parcela válida
     */
    public function gerar(array $parcelaIds, int $usuarioId): array
    {
        $convenio = $this->convenios->carregar('756');
        if (!$convenio || !$convenio['ativo']) {
            throw new \Exception('Convênio bancário do Sicoob não está cadastrado/ativo. Cadastre em Financeiro > Convênio Bancário.');
        }

        $escola = $this->escolaConfig->obter();

        $avisos = [];
        if (empty($convenio['agencia_dv'])) {
            $avisos[] = 'Dígito verificador da agência não confirmado — usando "0". Confirme com o gerente antes de usar em produção.';
        }
        if (empty($convenio['codigo_cedente_dv'])) {
            $avisos[] = 'Dígito verificador do código do cedente não confirmado — usando "0".';
        }
        if (empty($convenio['conta'])) {
            $avisos[] = 'Campo "conta" não configurado — usando o código do cedente no lugar (comum em convênios Sicoob, mas não confirmado pro convênio desta escola).';
        }

        $agenciaDv = $convenio['agencia_dv'] ?: '0';
        $cedenteDv = $convenio['codigo_cedente_dv'] ?: '0';
        $conta = $convenio['conta'] ?: $convenio['codigo_cedente'];

        return DB::transaction(function () use ($convenio, $escola, $parcelaIds, $usuarioId, $avisos, $agenciaDv, $cedenteDv, $conta) {
            // Trava a linha do convênio pra alocar nosso_numero sem colisão caso
            // duas remessas sejam geradas ao mesmo tempo.
            $proximoNossoNumero = (int) DB::table('convenios_cobranca_bancaria')
                ->where('id', $convenio['id'])
                ->lockForUpdate()
                ->value('proximo_nosso_numero');

            $numeroRemessa = (int) DB::table('remessas_sicoob')
                ->where('convenio_id', $convenio['id'])
                ->max('numero_remessa') + 1;

            $headerData = $this->cnab->arrayParaLatin1([
                // 'literal_servico' precisa ser passado explicitamente: o default
                // da biblioteca ('COBRANÇA') está hardcoded em UTF-8 no código
                // fonte dela, e o "Ç" em UTF-8 ocupa 2 bytes — sem isso aqui, a
                // linha de cabeçalho sai com 401 bytes em vez de 400.
                'literal_servico' => 'COBRANCA',
                'nome_empresa' => $escola['nome_escola'],
                'agencia' => $convenio['agencia'],
                'agencia_dv' => $agenciaDv,
                'codigo_beneficiario' => $convenio['codigo_cedente'],
                'codigo_beneficiario_dv' => $cedenteDv,
                'conta' => $conta,
                'conta_dv' => $cedenteDv,
                'numero_convenio' => ' ',
                'tipo_inscricao' => 2, // CNPJ (o beneficiário é sempre a escola, pessoa jurídica)
                'numero_inscricao' => $this->cnab->apenasDigitos($escola['cnpj']),
                'numero_sequencial_arquivo' => $numeroRemessa,
            ]);

            $arquivo = new Remessa(756, 'cnab400', $headerData);

            $puladas = [];
            $boletosGerados = [];

            foreach ($parcelaIds as $parcelaId) {
                $parcelaId = (int) $parcelaId;

                $parcela = DB::table('parcelas as p')
                    ->join('alunos as a', 'a.id', '=', 'p.aluno_id')
                    ->where('p.id', $parcelaId)
                    ->first(['p.id', 'p.valor_final', 'p.data_vencimento', 'p.status', 'p.aluno_id']);

                if (!$parcela) {
                    $puladas[] = ['parcela_id' => $parcelaId, 'motivo' => 'Parcela não encontrada.'];
                    continue;
                }
                if (!in_array($parcela->status, ['pendente', 'vencido'], true)) {
                    $puladas[] = ['parcela_id' => $parcelaId, 'motivo' => "Parcela com status '{$parcela->status}' não pode gerar boleto."];
                    continue;
                }

                $temBoletoAtivo = DB::table('boletos_bancarios')
                    ->where('parcela_id', $parcelaId)
                    ->whereIn('status', ['gerado', 'enviado'])
                    ->exists();
                if ($temBoletoAtivo) {
                    $puladas[] = ['parcela_id' => $parcelaId, 'motivo' => 'Já existe um boleto ativo (gerado ou enviado) para esta parcela.'];
                    continue;
                }

                $responsavel = $this->financeiro->buscarResponsavelPagador((int) $parcela->aluno_id);
                if (!$responsavel) {
                    $puladas[] = ['parcela_id' => $parcelaId, 'motivo' => 'Aluno sem responsável cadastrado.'];
                    continue;
                }
                $camposEndereco = ['cpf', 'endereco', 'bairro', 'cidade', 'uf', 'cep'];
                $faltando = array_filter($camposEndereco, fn ($c) => empty($responsavel[$c]));
                if ($faltando) {
                    $puladas[] = [
                        'parcela_id' => $parcelaId,
                        'motivo' => 'Responsável sem ' . implode(', ', $faltando) . ' cadastrado — obrigatório pro boleto.',
                    ];
                    continue;
                }
                $cpfDigitos = $this->cnab->apenasDigitos($responsavel['cpf']);
                if (strlen($cpfDigitos) !== 11) {
                    $puladas[] = ['parcela_id' => $parcelaId, 'motivo' => 'CPF do responsável inválido.'];
                    continue;
                }

                $nossoNumero = $proximoNossoNumero++;

                $arquivo->inserirDetalhe($this->cnab->arrayParaLatin1([
                    'seu_numero' => (string) $parcelaId,
                    'nosso_numero' => $nossoNumero,
                    'numero_parcela' => 1,
                    'codigo_movimento' => '01', // entrada de título
                    'carteira_banco' => $convenio['carteira'],
                    'cod_carteira' => $convenio['carteira'],
                    'numero_documento' => (string) $parcelaId,
                    'data_vencimento' => $parcela->data_vencimento,
                    'valor' => (float) $parcela->valor_final,
                    'especie_titulo' => 1, // DM
                    'data_emissao' => now()->toDateString(),
                    'tipo_inscricao' => 1, // CPF (pagador é o responsável, pessoa física)
                    'numero_inscricao' => $cpfDigitos,
                    'nome_pagador' => $responsavel['nome_completo'],
                    'endereco_pagador' => $responsavel['endereco'],
                    'bairro_pagador' => $responsavel['bairro'],
                    'cep_pagador' => $this->cnab->apenasDigitos($responsavel['cep']),
                    'cidade_pagador' => $responsavel['cidade'],
                    'uf_pagador' => $responsavel['uf'],
                    'prazo_protesto' => 0,
                ]));

                $boletosGerados[] = [
                    'parcela_id' => $parcelaId,
                    'nosso_numero' => $nossoNumero,
                    'valor' => (float) $parcela->valor_final,
                    'data_vencimento' => $parcela->data_vencimento,
                ];
            }

            if (!$boletosGerados) {
                throw new \Exception('Nenhuma parcela válida pra gerar remessa. Veja os motivos de cada parcela pulada.');
            }

            $conteudo = $arquivo->getText();

            $dataHoje = now()->format('Ymd');
            $nomeArquivo = "{$convenio['agencia']}_{$convenio['codigo_cedente']}_{$dataHoje}_C400_" . str_pad((string) $numeroRemessa, 2, '0', STR_PAD_LEFT) . '.REM';

            $remessaId = DB::table('remessas_sicoob')->insertGetId([
                'convenio_id' => $convenio['id'],
                'numero_remessa' => $numeroRemessa,
                'nome_arquivo' => $nomeArquivo,
                'quantidade_boletos' => count($boletosGerados),
                'gerado_por' => $usuarioId,
            ]);

            foreach ($boletosGerados as $b) {
                DB::table('boletos_bancarios')->insert([
                    'parcela_id' => $b['parcela_id'],
                    'convenio_id' => $convenio['id'],
                    'remessa_id' => $remessaId,
                    'nosso_numero' => (string) $b['nosso_numero'], // coluna é VARCHAR
                    'status' => 'gerado',
                    'data_emissao' => now()->toDateString(),
                    'data_vencimento' => $b['data_vencimento'],
                    'valor' => $b['valor'],
                ]);
            }

            // Avança o contador só pelo que realmente foi emitido nesta remessa.
            DB::table('convenios_cobranca_bancaria')->where('id', $convenio['id'])->update([
                'proximo_nosso_numero' => $proximoNossoNumero,
            ]);

            return [
                'conteudo' => $conteudo,
                'nome_arquivo' => $nomeArquivo,
                'quantidade' => count($boletosGerados),
                'avisos' => $avisos,
                'puladas' => $puladas,
                'remessa_id' => $remessaId,
            ];
        });
    }
}
