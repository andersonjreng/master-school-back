<?php

namespace App\Services\Financeiro\Sicoob;

use App\Services\Financeiro\FinanceiroHelperService;
use CnabPHP\Retorno;
use CnabPHP\RetornoAbstract;
use Illuminate\Support\Facades\DB;

/**
 * Leitura do arquivo de retorno CNAB 400 do Sicoob (banco 756) — Cenário A:
 * o usuário baixa o .RET no Sicoob Net Empresarial e importa na Central
 * Sicoob.
 *
 * Em dois tempos:
 *  1. analisar(): lê o arquivo e diz o que VAI acontecer com cada título
 *     (pagamentos, registros, rejeições, baixas…) sem gravar nada — a tela
 *     mostra essa prévia para o usuário conferir.
 *  2. aplicar(): grava. Liquidação (05/06/15) dá baixa na parcela e gera
 *     recibo; demais ocorrências atualizam o status do boleto. Cada linha
 *     fica registrada em retorno_sicoob_itens, e o hash do arquivo impede
 *     importar o mesmo retorno duas vezes.
 */
class RetornoSicoobService
{
    public function __construct(
        private ConvenioCobrancaService $convenios,
        private FinanceiroHelperService $financeiro,
    ) {
    }

    /** Prévia — não grava nada. */
    public function analisar(string $conteudoArquivo, string $nomeArquivo): array
    {
        [$convenio, $registros] = $this->ler($conteudoArquivo);
        $hash = hash('sha256', $conteudoArquivo);
        $jaImportado = DB::table('retornos_sicoob')->where('hash_arquivo', $hash)->first(['id', 'processado_em']);

        $itens = array_map(fn ($r) => $this->classificar($r, (int) $convenio['id']), $registros);

        return [
            'nome_arquivo' => $nomeArquivo,
            'hash'         => $hash,
            'ja_importado' => $jaImportado ? ['retorno_id' => (int) $jaImportado->id, 'em' => $jaImportado->processado_em] : null,
            'resumo'       => $this->resumir($itens),
            'itens'        => $itens,
        ];
    }

    /** Grava o retorno (ver topo da classe). */
    public function aplicar(string $conteudoArquivo, string $nomeArquivo, int $usuarioId): array
    {
        [$convenio, $registros] = $this->ler($conteudoArquivo);
        $hash = hash('sha256', $conteudoArquivo);
        if (DB::table('retornos_sicoob')->where('hash_arquivo', $hash)->exists()) {
            throw new \Exception('Este arquivo de retorno já foi importado antes — nada foi alterado.');
        }

        // Tudo ou nada: se algo quebrar no meio, o arquivo pode ser importado de novo.
        return DB::transaction(fn () => $this->gravar($convenio, $registros, $hash, $nomeArquivo, $usuarioId));
    }

    private function gravar(array $convenio, array $registros, string $hash, string $nomeArquivo, int $usuarioId): array
    {
        $retornoId = DB::table('retornos_sicoob')->insertGetId([
            'convenio_id'          => $convenio['id'],
            'nome_arquivo'         => $nomeArquivo,
            'hash_arquivo'         => $hash,
            'quantidade_registros' => count($registros),
            'processado_por'       => $usuarioId,
        ]);

        $itens = [];
        foreach ($registros as $registro) {
            $item = $this->classificar($registro, (int) $convenio['id']);
            $item = $this->executar($item, $usuarioId, $retornoId);
            $itens[] = $item;

            DB::table('retorno_sicoob_itens')->insert([
                'retorno_id'        => $retornoId,
                'nosso_numero'      => $item['nosso_numero'],
                'boleto_id'         => $item['boleto_id'],
                'parcela_id'        => $item['parcela_id'],
                'codigo_ocorrencia' => $item['codigo'],
                'ocorrencia'        => mb_substr($item['ocorrencia'], 0, 150),
                'categoria'         => $item['categoria'],
                'resultado'         => $item['resultado'],
                'valor_titulo'      => $item['valor_titulo'],
                'valor_pago'        => $item['valor_pago'],
                'data_ocorrencia'   => $item['data_ocorrencia'],
                'mensagem'          => $item['mensagem'] ? mb_substr($item['mensagem'], 0, 255) : null,
            ]);
        }

        $resumo = $this->resumir($itens);
        DB::table('retornos_sicoob')->where('id', $retornoId)->update([
            'quantidade_baixados' => $resumo['pagamentos']['aplicados'],
            'valor_baixado'       => $resumo['pagamentos']['valor_aplicado'],
            'observacoes'         => "{$resumo['pagamentos']['aplicados']} pagamento(s), {$resumo['registros']} registro(s), {$resumo['rejeicoes']} rejeição(ões), {$resumo['atencao']} para conferir.",
        ]);

        return ['retorno_id' => $retornoId, 'resumo' => $resumo, 'itens' => $itens];
    }

    // ── Internos ─────────────────────────────────────────────

    /** @return array{0: array, 1: array} [convênio, registros do arquivo] */
    private function ler(string $conteudo): array
    {
        try {
            $arquivo = new Retorno($conteudo);
        } catch (\Throwable $e) {
            throw new \Exception('Não foi possível ler o arquivo — confira se é mesmo um retorno CNAB 400 do Sicoob (.RET). (' . $e->getMessage() . ')');
        }
        $banco = (int) RetornoAbstract::$banco;
        if ($banco !== 756) {
            throw new \Exception("Este arquivo não é do Sicoob (banco 756) — banco no cabeçalho: $banco.");
        }
        $convenio = $this->convenios->carregar('756');
        if (!$convenio) {
            throw new \Exception('Convênio do Sicoob não cadastrado (Configurações → Parametrizações → Financeiro).');
        }
        return [$convenio, $arquivo->getRegistros()];
    }

    /** O que este registro significa e a qual boleto/parcela se refere. */
    private function classificar(object $registro, int $convenioId): array
    {
        $nossoNumero = (string) (int) $registro->nosso_numero;
        $codigo = SicoobOcorrencias::normalizar($registro->codigo_movimento);
        [$ocorrencia, $categoria] = SicoobOcorrencias::descrever($codigo);

        $boleto = DB::table('boletos_bancarios as b')
            ->join('parcelas as p', 'p.id', '=', 'b.parcela_id')
            ->join('alunos as a', 'a.id', '=', 'p.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('b.convenio_id', $convenioId)
            ->where('b.nosso_numero', $nossoNumero)
            ->orderByDesc('b.id')
            ->first(['b.id', 'b.status', 'b.parcela_id', 'p.status as parcela_status', 'p.numero_parcela', 'u.nome_completo as aluno']);

        $valorPago = $registro->vlr_pago !== null ? (float) $registro->vlr_pago : null;
        $item = [
            'nosso_numero'    => $nossoNumero,
            'codigo'          => $codigo,
            'ocorrencia'      => $ocorrencia,
            'categoria'       => $categoria,
            'boleto_id'       => $boleto ? (int) $boleto->id : null,
            'parcela_id'      => $boleto ? (int) $boleto->parcela_id : null,
            'aluno'           => $boleto->aluno ?? null,
            'parcela'         => $boleto ? (int) $boleto->numero_parcela : null,
            'valor_titulo'    => $registro->valor !== null ? (float) $registro->valor : null,
            'valor_pago'      => $valorPago,
            'data_ocorrencia' => $registro->data_ocorrencia ? (string) $registro->data_ocorrencia : null,
            'acao'            => 'nenhuma',
            'resultado'       => 'ignorado',
            'mensagem'        => null,
        ];

        if (!$boleto) {
            $item['resultado'] = 'nao_encontrado';
            $item['mensagem'] = 'Nenhum boleto com esse nosso número foi emitido por este sistema (pode ser do sistema antigo).';
            return $item;
        }

        switch ($categoria) {
            case 'liquidacao':
                if ($boleto->parcela_status === 'pago') {
                    $item['mensagem'] = 'Parcela já estava paga no sistema — confira se houve pagamento em duplicidade.';
                } elseif (in_array($boleto->parcela_status, ['cancelado', 'negociado'], true)) {
                    $item['mensagem'] = "Parcela está {$boleto->parcela_status} — pagamento recebido precisa ser conferido.";
                } else {
                    $item['acao'] = 'baixar';
                }
                break;
            case 'registro':
                $item['acao'] = in_array($boleto->status, ['gerado', 'enviado'], true) ? 'registrar' : 'nenhuma';
                break;
            case 'rejeicao':
                $item['acao'] = 'rejeitar';
                $item['mensagem'] = 'O banco recusou o título — confira os dados do responsável e gere o boleto novamente.';
                break;
            case 'baixa':
                $item['acao'] = $boleto->status === 'pago' ? 'nenhuma' : 'baixar_boleto';
                break;
            default:
                $item['acao'] = 'anotar';
        }

        return $item;
    }

    private function executar(array $item, int $usuarioId, int $retornoId): array
    {
        $atualizarBoleto = function (?string $status) use ($item) {
            $campos = [
                'ultima_ocorrencia'           => $item['codigo'],
                'ultima_ocorrencia_descricao' => mb_substr($item['ocorrencia'], 0, 150),
                'ultima_ocorrencia_em'        => $item['data_ocorrencia'],
            ];
            if ($status) {
                $campos['status'] = $status;
            }
            DB::table('boletos_bancarios')->where('id', $item['boleto_id'])->update($campos);
        };

        if (!$item['boleto_id']) {
            return $item;
        }

        try {
            switch ($item['acao']) {
                case 'baixar':
                    $r = $this->financeiro->aplicarBaixaParcela(
                        $item['parcela_id'],
                        (float) ($item['valor_pago'] ?? $item['valor_titulo'] ?? 0),
                        $item['data_ocorrencia'] ?: now()->toDateString(),
                        'boleto',
                        "Baixa automática via retorno Sicoob (nosso número {$item['nosso_numero']})",
                        $usuarioId,
                        $item['boleto_id'],
                        $retornoId
                    );
                    $atualizarBoleto('pago');
                    $item['resultado'] = 'aplicado';
                    $item['mensagem'] = "Baixa registrada — recibo {$r['numero_recibo']}.";
                    break;
                case 'registrar':
                    $atualizarBoleto('registrado');
                    $item['resultado'] = 'aplicado';
                    break;
                case 'rejeitar':
                    $atualizarBoleto('rejeitado');
                    $item['resultado'] = 'aplicado';
                    break;
                case 'baixar_boleto':
                    $atualizarBoleto('baixado');
                    $item['resultado'] = 'aplicado';
                    break;
                default:
                    $atualizarBoleto(null);
            }
        } catch (\Throwable $e) {
            $item['resultado'] = 'erro';
            $item['mensagem'] = $e->getMessage();
        }

        return $item;
    }

    private function resumir(array $itens): array
    {
        $liq = array_filter($itens, fn ($i) => $i['categoria'] === 'liquidacao');
        $aBaixar = array_filter($liq, fn ($i) => $i['acao'] === 'baixar');
        $aplicados = array_filter($liq, fn ($i) => $i['resultado'] === 'aplicado');

        return [
            'total'      => count($itens),
            'pagamentos' => [
                'quantidade'     => count($liq),
                'a_baixar'       => count($aBaixar),
                'valor'          => round(array_sum(array_map(fn ($i) => (float) ($i['valor_pago'] ?? 0), $liq)), 2),
                'aplicados'      => count($aplicados),
                'valor_aplicado' => round(array_sum(array_map(fn ($i) => (float) ($i['valor_pago'] ?? 0), $aplicados)), 2),
            ],
            'registros'  => count(array_filter($itens, fn ($i) => $i['categoria'] === 'registro')),
            'rejeicoes'  => count(array_filter($itens, fn ($i) => $i['categoria'] === 'rejeicao')),
            'baixas'     => count(array_filter($itens, fn ($i) => $i['categoria'] === 'baixa')),
            'outros'     => count(array_filter($itens, fn ($i) => in_array($i['categoria'], ['alteracao', 'informativo'], true))),
            // Precisam de olho humano: não encontrados, erros e pagamentos que não viraram baixa.
            'atencao'    => count(array_filter($itens, fn ($i) => in_array($i['resultado'], ['nao_encontrado', 'erro'], true)
                || ($i['categoria'] === 'liquidacao' && $i['acao'] !== 'baixar'))),
        ];
    }
}
