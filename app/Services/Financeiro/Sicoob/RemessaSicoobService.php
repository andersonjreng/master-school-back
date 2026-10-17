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
    /** Boleto ainda "vivo" no banco — impede gerar outro para a mesma parcela. */
    public const BOLETO_ATIVO = ['gerado', 'enviado', 'registrado'];

    public function __construct(
        private ConvenioCobrancaService $convenios,
        private EscolaConfigService $escolaConfig,
        private FinanceiroHelperService $financeiro,
        private CnabEncodingService $cnab,
    ) {
    }

    /**
     * Confere se a parcela pode virar boleto agora.
     *
     * @return array{ok: bool, motivo: ?string, parcela: ?object, responsavel: ?array, cpf: ?string}
     */
    public function validarParcela(int $parcelaId): array
    {
        $falha = fn (string $motivo, $parcela = null, $responsavel = null) => [
            'ok' => false, 'motivo' => $motivo, 'parcela' => $parcela, 'responsavel' => $responsavel, 'cpf' => null,
        ];

        $parcela = DB::table('parcelas as p')
            ->join('alunos as a', 'a.id', '=', 'p.aluno_id')
            ->where('p.id', $parcelaId)
            ->first(['p.id', 'p.valor_final', 'p.data_vencimento', 'p.status', 'p.aluno_id']);

        if (!$parcela) {
            return $falha('Parcela não encontrada.');
        }
        if (!in_array($parcela->status, ['pendente', 'vencido'], true)) {
            return $falha("Parcela com status '{$parcela->status}' não pode gerar boleto.", $parcela);
        }
        if (DB::table('boletos_bancarios')->where('parcela_id', $parcelaId)->whereIn('status', self::BOLETO_ATIVO)->exists()) {
            return $falha('Já existe um boleto ativo para esta parcela.', $parcela);
        }

        $responsavel = $this->financeiro->buscarResponsavelPagador((int) $parcela->aluno_id);
        if (!$responsavel) {
            return $falha('Aluno sem responsável cadastrado.', $parcela);
        }
        $rotulos = ['cpf' => 'CPF', 'endereco' => 'endereço', 'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'UF', 'cep' => 'CEP'];
        $faltando = array_keys(array_filter($rotulos, fn ($r, $c) => empty($responsavel[$c]), ARRAY_FILTER_USE_BOTH));
        if ($faltando) {
            return $falha('Responsável sem ' . implode(', ', array_map(fn ($c) => $rotulos[$c], $faltando)) . ' — obrigatório no boleto.', $parcela, $responsavel);
        }
        $cpf = $this->cnab->apenasDigitos($responsavel['cpf']);
        if (strlen($cpf) !== 11) {
            return $falha('CPF do responsável inválido.', $parcela, $responsavel);
        }
        if (strlen($this->cnab->apenasDigitos($responsavel['cep'])) !== 8) {
            return $falha('CEP do responsável inválido.', $parcela, $responsavel);
        }

        return ['ok' => true, 'motivo' => null, 'parcela' => $parcela, 'responsavel' => $responsavel, 'cpf' => $cpf];
    }

    /**
     * Prévia da remessa: parcelas em aberto que vencem até hoje + $dias e
     * ainda não têm boleto ativo, cada uma dizendo se está pronta ou o que
     * falta corrigir no cadastro.
     */
    public function candidatas(int $dias): array
    {
        $limite = now()->addDays(max(0, $dias))->toDateString();

        $linhas = DB::table('parcelas as p')
            ->join('alunos as a', 'a.id', '=', 'p.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->leftJoin('turma_aluno as ta', 'ta.aluno_id', '=', 'a.id')
            ->leftJoin('turmas as t', 't.id', '=', 'ta.turma_id')
            ->whereIn('p.status', ['pendente', 'vencido'])
            ->where('p.data_vencimento', '<=', $limite)
            ->whereNotExists(fn ($q) => $q->from('boletos_bancarios as b')
                ->whereColumn('b.parcela_id', 'p.id')->whereIn('b.status', self::BOLETO_ATIVO))
            ->orderBy('p.data_vencimento')
            ->orderBy('u.nome_completo')
            ->get(['p.id', 'p.numero_parcela', 'p.valor_final', 'p.data_vencimento', 'p.status', 'a.id as aluno_id',
                'u.nome_completo as aluno', 't.nome_turma as turma']);

        // Um aluno com 2 vínculos de turma repetiria a parcela: fica a primeira.
        $vistos = [];
        $itens = [];
        foreach ($linhas as $l) {
            if (isset($vistos[$l->id])) {
                continue;
            }
            $vistos[$l->id] = true;
            $v = $this->validarParcela((int) $l->id);
            $itens[] = [
                'parcela_id'  => (int) $l->id,
                'aluno_id'    => (int) $l->aluno_id,
                'aluno'       => $l->aluno,
                'turma'       => $l->turma,
                'parcela'     => (int) $l->numero_parcela,
                'valor'       => (float) $l->valor_final,
                'vencimento'  => $l->data_vencimento,
                'vencida'     => $l->status === 'vencido' || $l->data_vencimento < now()->toDateString(),
                'pronta'      => $v['ok'],
                'pendencia'   => $v['motivo'],
                'responsavel' => $v['responsavel']['nome_completo'] ?? null,
                'responsavel_id' => isset($v['responsavel']['id']) ? (int) $v['responsavel']['id'] : null,
            ];
        }

        return $itens;
    }

    /** Remessa subida no Sicoob Net Empresarial pelo usuário: boletos passam a "enviado". */
    public function marcarEnviada(int $remessaId, int $usuarioId): void
    {
        $remessa = DB::table('remessas_sicoob')->where('id', $remessaId)->first(['id', 'status']);
        if (!$remessa) {
            throw new \Exception('Remessa não encontrada.');
        }
        DB::transaction(function () use ($remessaId, $usuarioId) {
            DB::table('remessas_sicoob')->where('id', $remessaId)->update([
                'status' => 'enviado', 'enviado_em' => now(), 'enviado_por' => $usuarioId,
            ]);
            DB::table('boletos_bancarios')->where('remessa_id', $remessaId)->where('status', 'gerado')->update(['status' => 'enviado']);
        });
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

        // Conta corrente + DV vão em todo registro de detalhe (posições 23-31) e
        // NÃO são derivados do código do cedente (no convênio desta escola:
        // cedente 164274-0, conta 421945-7, confirmado no retorno real) —
        // melhor travar do que mandar um arquivo pro banco com a conta errada.
        if (empty($convenio['conta']) || (string) ($convenio['conta_dv'] ?? '') === '') {
            throw new \Exception('Conta corrente e dígito da conta não cadastrados no convênio — obrigatórios pra remessa.');
        }

        $escola = $this->escolaConfig->obter();

        // DV "0" é um valor válido — empty() trataria como não preenchido.
        $avisos = [];
        if ((string) ($convenio['agencia_dv'] ?? '') === '') {
            $avisos[] = 'Dígito verificador da agência não confirmado — usando "0". Confirme com o gerente antes de usar em produção.';
        }
        if ((string) ($convenio['codigo_cedente_dv'] ?? '') === '') {
            $avisos[] = 'Dígito verificador do código do cedente não confirmado — usando "0".';
        }

        $agenciaDv = $convenio['agencia_dv'] ?: '0';
        $cedenteDv = $convenio['codigo_cedente_dv'] ?: '0';
        $conta = $convenio['conta'];
        $contaDv = $convenio['conta_dv'];

        return DB::transaction(function () use ($convenio, $escola, $parcelaIds, $usuarioId, $avisos, $agenciaDv, $cedenteDv, $conta, $contaDv) {
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
                'conta_dv' => $contaDv,
                'numero_convenio' => ' ',
                'tipo_inscricao' => 2, // CNPJ (o beneficiário é sempre a escola, pessoa jurídica)
                'numero_inscricao' => $this->cnab->apenasDigitos($escola['cnpj']),
                'numero_sequencial_arquivo' => $numeroRemessa,
            ]);

            $arquivo = new Remessa(756, 'cnab400', $headerData);

            $puladas = [];
            $boletosGerados = [];

            // Repetida na lista viraria 2 boletos pra mesma parcela: validarParcela()
            // só enxerga boletos já gravados, e aqui eles são gravados depois do loop.
            foreach (array_unique(array_map('intval', $parcelaIds)) as $parcelaId) {

                $v = $this->validarParcela($parcelaId);
                if (!$v['ok']) {
                    $puladas[] = ['parcela_id' => $parcelaId, 'motivo' => $v['motivo']];
                    continue;
                }
                $parcela = $v['parcela'];
                $responsavel = $v['responsavel'];
                $cpfDigitos = $v['cpf'];

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
                'valor_total' => array_sum(array_column($boletosGerados, 'valor')),
                // Guardado para baixar de novo pela Central Sicoob (base64: CNAB é ISO-8859-1).
                'conteudo_base64' => base64_encode($conteudo),
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
