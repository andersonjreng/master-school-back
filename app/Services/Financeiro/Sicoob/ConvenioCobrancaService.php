<?php

namespace App\Services\Financeiro\Sicoob;

use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/helpers/convenio_cobranca_helper.php.
 *
 * Convênio de cobrança bancária — dados do cedente junto ao banco, usados na
 * emissão de boleto (arquivo de remessa) e na baixa (arquivo de retorno).
 * Hoje só existe o convênio do Sicoob (banco_codigo = '756'), mas a tabela já
 * comporta mais de um convênio/banco.
 */
class ConvenioCobrancaService
{
    /**
     * Carrega o convênio de cobrança de um banco. Retorna null se ainda não
     * houver nenhum cadastrado.
     */
    public function carregar(string $bancoCodigo = '756'): ?array
    {
        $row = DB::table('convenios_cobranca_bancaria')
            ->where('banco_codigo', $bancoCodigo)
            ->orderByDesc('ativo')
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return null;
        }

        $arr = (array) $row;
        $arr['proximo_nosso_numero'] = (int) $row->proximo_nosso_numero;
        $arr['ativo'] = (bool) $row->ativo;

        return $arr;
    }

    /**
     * Cria ou atualiza o convênio de um banco. Upsert por banco_codigo — por
     * enquanto só existe um convênio ativo por banco, então "salvar" sempre
     * atualiza o registro existente em vez de acumular duplicatas.
     *
     * NÃO mexe em proximo_nosso_numero — esse contador é avançado só pelo
     * gerador de remessa, nunca por essa tela.
     */
    public function salvar(array $dados): array
    {
        $existente = $this->carregar($dados['banco_codigo']);

        $campos = [
            'banco_nome' => $dados['banco_nome'],
            'codigo_cedente' => $dados['codigo_cedente'],
            'codigo_cedente_dv' => $dados['codigo_cedente_dv'],
            'agencia' => $dados['agencia'],
            'agencia_dv' => $dados['agencia_dv'],
            'conta' => $dados['conta'],
            'carteira' => $dados['carteira'],
            'variacao_carteira' => $dados['variacao_carteira'],
            'ativo' => (int) $dados['ativo'],
        ];

        if ($existente) {
            DB::table('convenios_cobranca_bancaria')->where('id', $existente['id'])->update($campos);
        } else {
            DB::table('convenios_cobranca_bancaria')->insert($campos + ['banco_codigo' => $dados['banco_codigo']]);
        }

        return $this->carregar($dados['banco_codigo']);
    }
}
