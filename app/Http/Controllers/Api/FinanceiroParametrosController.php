<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\FinanceiroConfigService;
use App\Services\Financeiro\Sicoob\ConvenioCobrancaService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Configurações → Parametrizações → Financeiro: modo de cobrança, encargos,
 * contrato na matrícula e dados do convênio Sicoob (inclusive o próximo
 * nosso número, que só pode avançar).
 */
class FinanceiroParametrosController extends Controller
{
    public function __construct(
        private FinanceiroConfigService $config,
        private ConvenioCobrancaService $convenios,
        private LogSistemaService $log,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'config'               => $this->config->todas(),
            'convenio'             => $this->convenios->carregar('756'),
            'nosso_numero_minimo'  => $this->nossoNumeroMinimo(),
            'boletos_emitidos'     => DB::table('boletos_bancarios')->count(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $c = (array) $request->input('config', []);

        if (isset($c['modo_cobranca']) && !in_array($c['modo_cobranca'], FinanceiroConfigService::MODOS, true)) {
            return response()->json(['error' => 'Modo de cobrança inválido.'], 422);
        }
        if (isset($c['financeiro_na_matricula']) && !in_array($c['financeiro_na_matricula'], FinanceiroConfigService::MATRICULA, true)) {
            return response()->json(['error' => 'Opção de contrato na matrícula inválida.'], 422);
        }
        $faixas = [
            'multa_percentual'          => [0, 2, 'Multa deve ficar entre 0% e 2% (limite do Código de Defesa do Consumidor).'],
            'juros_diario_percentual'   => [0, 1, 'Juros ao dia deve ficar entre 0% e 1%.'],
            'dias_carencia'             => [0, 60, 'Carência deve ficar entre 0 e 60 dias.'],
            'remessa_dias_antecedencia' => [0, 90, 'Antecedência da remessa deve ficar entre 0 e 90 dias.'],
        ];
        foreach ($faixas as $chave => [$min, $max, $msg]) {
            if (isset($c[$chave]) && (!is_numeric($c[$chave]) || $c[$chave] < $min || $c[$chave] > $max)) {
                return response()->json(['error' => $msg], 422);
            }
        }
        if (isset($c['instrucoes_boleto']) && mb_strlen((string) $c['instrucoes_boleto']) > 400) {
            return response()->json(['error' => 'Instruções do boleto: no máximo 400 caracteres.'], 422);
        }

        $conv = $request->input('convenio');
        $modo = $c['modo_cobranca'] ?? $this->config->todas()['modo_cobranca'];
        if ($modo === 'sicoob_arquivo') {
            $atual = is_array($conv) ? $conv : ($this->convenios->carregar('756') ?? []);
            foreach (['codigo_cedente' => 'código do cedente', 'agencia' => 'agência', 'carteira' => 'carteira'] as $campo => $rotulo) {
                if (!trim((string) ($atual[$campo] ?? ''))) {
                    return response()->json(['error' => "Para cobrar por boleto Sicoob, preencha o $rotulo do convênio."], 422);
                }
            }
        }

        $minimo = $this->nossoNumeroMinimo();
        $proximo = is_array($conv) && isset($conv['proximo_nosso_numero']) ? (int) $conv['proximo_nosso_numero'] : null;
        if ($proximo !== null && $proximo < $minimo) {
            return response()->json(['error' => "O próximo nosso número não pode ser menor que $minimo — já existem boletos emitidos até " . ($minimo - 1) . '.'], 422);
        }

        DB::transaction(function () use ($c, $conv, $proximo) {
            $this->config->salvar($c);
            if (is_array($conv)) {
                $salvo = $this->convenios->salvar([
                    'banco_codigo'      => '756',
                    'banco_nome'        => trim((string) ($conv['banco_nome'] ?? 'Sicoob')) ?: 'Sicoob',
                    'codigo_cedente'    => trim((string) ($conv['codigo_cedente'] ?? '')),
                    'codigo_cedente_dv' => ConvenioCobrancaService::dv($conv['codigo_cedente_dv'] ?? ''),
                    'agencia'           => trim((string) ($conv['agencia'] ?? '')),
                    'agencia_dv'        => ConvenioCobrancaService::dv($conv['agencia_dv'] ?? ''),
                    'conta'             => trim((string) ($conv['conta'] ?? '')),
                    'conta_dv'          => ConvenioCobrancaService::dv($conv['conta_dv'] ?? ''),
                    'carteira'          => trim((string) ($conv['carteira'] ?? '')),
                    'variacao_carteira' => trim((string) ($conv['variacao_carteira'] ?? '')) ?: null,
                    'ativo'             => (bool) ($conv['ativo'] ?? true),
                ]);
                if ($proximo !== null) {
                    DB::table('convenios_cobranca_bancaria')->where('id', $salvo['id'])->update(['proximo_nosso_numero' => $proximo]);
                }
            }
        });

        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'PARAMETROS_FINANCEIRO',
            '/api/parametrizacoes/financeiro', 'PUT', 'Parâmetros do financeiro atualizados', 200,
            ['config' => $c, 'proximo_nosso_numero' => $proximo]
        );

        return $this->show();
    }

    /** Nosso número nunca pode repetir: o próximo tem que passar do maior já emitido. */
    private function nossoNumeroMinimo(): int
    {
        $maior = (int) DB::table('boletos_bancarios')->max(DB::raw('CAST(nosso_numero AS UNSIGNED)'));
        return $maior + 1;
    }
}
