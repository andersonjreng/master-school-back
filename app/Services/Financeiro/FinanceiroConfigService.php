<?php

namespace App\Services\Financeiro;

use Illuminate\Support\Facades\DB;

/**
 * Parâmetros do financeiro (Configurações → Parametrizações → Financeiro),
 * guardados em configuracoes_financeiras (chave/valor). Os padrões abaixo
 * valem enquanto a chave não existir no banco.
 */
class FinanceiroConfigService
{
    public const MODOS = ['manual', 'sicoob_arquivo', 'asaas'];
    public const MATRICULA = ['perguntar', 'nao_perguntar'];

    private const PADROES = [
        'modo_cobranca'             => 'manual',
        'financeiro_na_matricula'   => 'perguntar',
        'remessa_dias_antecedencia' => '15',
        'instrucoes_boleto'         => 'Após o vencimento, cobrar multa e juros conforme contrato.',
        'multa_percentual'          => '2.00',
        'juros_diario_percentual'   => '0.0333',
        'dias_carencia'             => '5',
        'asaas_emissao_automatica'  => '0',
    ];

    public function todas(): array
    {
        $salvas = DB::table('configuracoes_financeiras')->pluck('valor', 'chave')->all();
        $c = array_merge(self::PADROES, $salvas);

        return [
            'modo_cobranca'             => in_array($c['modo_cobranca'], self::MODOS, true) ? $c['modo_cobranca'] : 'manual',
            'financeiro_na_matricula'   => in_array($c['financeiro_na_matricula'], self::MATRICULA, true) ? $c['financeiro_na_matricula'] : 'perguntar',
            'remessa_dias_antecedencia' => (int) $c['remessa_dias_antecedencia'],
            'instrucoes_boleto'         => (string) $c['instrucoes_boleto'],
            'multa_percentual'          => (float) $c['multa_percentual'],
            'juros_diario_percentual'   => (float) $c['juros_diario_percentual'],
            'dias_carencia'             => (int) $c['dias_carencia'],
            'asaas_emissao_automatica'  => $c['asaas_emissao_automatica'] === '1',
        ];
    }

    public function modoSicoob(): bool
    {
        return $this->todas()['modo_cobranca'] === 'sicoob_arquivo';
    }

    public function modoAsaas(): bool
    {
        return $this->todas()['modo_cobranca'] === 'asaas';
    }

    public function salvar(array $valores): void
    {
        foreach ($valores as $chave => $valor) {
            if (!array_key_exists($chave, self::PADROES)) {
                continue;
            }
            DB::table('configuracoes_financeiras')->updateOrInsert(['chave' => $chave], ['valor' => is_bool($valor) ? ($valor ? '1' : '0') : (string) $valor]);
        }
    }
}
