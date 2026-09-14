<?php

namespace App\Services\Financeiro;

use DateTime;
use Illuminate\Support\Facades\DB;

/**
 * Lógica compartilhada de criação de matrícula financeira + geração de parcelas.
 * Equivalente a criarMatriculaFinanceira()/alunoJaMatriculado() em
 * api/financeiro/matricula_helper.php. Usada tanto pelo cadastro individual
 * quanto pela matrícula em massa, pra não duplicar a regra de cálculo de
 * desconto/parcelas nos dois lugares.
 *
 * Lança Exception em caso de erro de validação — quem chama decide se aborta
 * tudo (uso individual) ou só pula aquele aluno e segue o lote (uso em massa).
 */
class MatriculaFinanceiraService
{
    /**
     * @param array $params {
     *   aluno_id: int, plano_id: int, ano_letivo: string, data_inicio: string (YYYY-MM-DD),
     *   desconto_padrao_id?: int|null, desconto_extra_tipo?: 'percentual'|'valor'|null,
     *   desconto_extra_valor?: float|null, desconto_extra_descricao?: string, observacoes?: string,
     * }
     * @return array{matricula_id:int, parcelas_geradas:int, valor_parcela:float}
     */
    public function criar(int $usuarioIdLogado, array $params): array
    {
        $alunoId = (int) ($params['aluno_id'] ?? 0);
        $planoId = (int) ($params['plano_id'] ?? 0);
        $descontoPadraoId = isset($params['desconto_padrao_id']) ? (int) $params['desconto_padrao_id'] : null;
        $descontoExtraTipo = $params['desconto_extra_tipo'] ?? null;
        $descontoExtraValor = isset($params['desconto_extra_valor']) ? (float) $params['desconto_extra_valor'] : null;
        $descontoExtraDescricao = trim($params['desconto_extra_descricao'] ?? '');
        $anoLetivo = trim($params['ano_letivo'] ?? '');
        $dataInicio = $params['data_inicio'] ?? null;
        $observacoes = trim($params['observacoes'] ?? '');

        if (!$alunoId || !$planoId || !$anoLetivo || !$dataInicio) {
            throw new \Exception('aluno_id, plano_id, ano_letivo e data_inicio são obrigatórios.');
        }
        if ($descontoExtraTipo !== null && !in_array($descontoExtraTipo, ['percentual', 'valor'], true)) {
            throw new \Exception('Tipo de desconto extra inválido.');
        }

        $plano = DB::table('planos_pagamento')->where('id', $planoId)->where('ativo', 1)
            ->first(['valor_total', 'numero_parcelas', 'dia_vencimento']);
        if (!$plano) {
            throw new \Exception('Plano não encontrado ou inativo.');
        }

        $valorDescontoPadrao = 0.0;
        $tipoDescontoPadrao = null;
        if ($descontoPadraoId) {
            $desc = DB::table('descontos_padrao')->where('id', $descontoPadraoId)->where('ativo', 1)
                ->first(['tipo', 'valor']);
            if ($desc) {
                $tipoDescontoPadrao = $desc->tipo;
                $valorDescontoPadrao = (float) $desc->valor;
            } else {
                $descontoPadraoId = null; // inválido, ignora
            }
        }

        $valorParcelaOriginal = round((float) $plano->valor_total / (int) $plano->numero_parcelas, 2);
        $descontoPorParcela = 0.0;

        if ($tipoDescontoPadrao === 'percentual') {
            $descontoPorParcela += $valorParcelaOriginal * ($valorDescontoPadrao / 100);
        } elseif ($tipoDescontoPadrao === 'valor') {
            $descontoPorParcela += $valorDescontoPadrao / (int) $plano->numero_parcelas;
        }

        if ($descontoExtraTipo === 'percentual' && $descontoExtraValor > 0) {
            $descontoPorParcela += $valorParcelaOriginal * ($descontoExtraValor / 100);
        } elseif ($descontoExtraTipo === 'valor' && $descontoExtraValor > 0) {
            $descontoPorParcela += $descontoExtraValor / (int) $plano->numero_parcelas;
        }

        $descontoPorParcela = round($descontoPorParcela, 2);
        // Desconto nunca deixa a parcela negativa — bolsa integral (100%) zera a parcela, não passa disso.
        $descontoPorParcela = min($descontoPorParcela, $valorParcelaOriginal);
        $valorFinalParcela = round($valorParcelaOriginal - $descontoPorParcela, 2);

        return DB::transaction(function () use (
            $alunoId, $planoId, $descontoPadraoId, $descontoExtraTipo, $descontoExtraValor,
            $descontoExtraDescricao, $anoLetivo, $dataInicio, $observacoes, $usuarioIdLogado,
            $plano, $valorParcelaOriginal, $descontoPorParcela, $valorFinalParcela,
        ) {
            // matriculas_financeiras não tem UNIQUE(aluno_id, ano_letivo, status) no
            // schema local — diferente do que o código legado presumia (contava com
            // errno 1062 pra barrar duplicata, que nunca dispararia de fato). Checagem
            // em nível de app dentro da transação pra não deixar brecha de duplicidade.
            if ($this->jaMatriculado($alunoId, $anoLetivo)) {
                throw new \Exception('Já existe uma matrícula financeira para este aluno neste ano letivo.');
            }

            $matriculaId = DB::table('matriculas_financeiras')->insertGetId([
                'aluno_id' => $alunoId,
                'plano_id' => $planoId,
                'desconto_padrao_id' => $descontoPadraoId,
                'desconto_extra_tipo' => $descontoExtraTipo,
                'desconto_extra_valor' => $descontoExtraValor,
                'desconto_extra_descricao' => $descontoExtraDescricao,
                'ano_letivo' => $anoLetivo,
                'data_inicio' => $dataInicio,
                'observacoes' => $observacoes,
                'criado_por' => $usuarioIdLogado,
            ]);

            $dataInicioObj = new DateTime($dataInicio);
            $diaVenc = (int) $plano->dia_vencimento;
            $numParcelas = (int) $plano->numero_parcelas;

            for ($i = 1; $i <= $numParcelas; $i++) {
                $venc = clone $dataInicioObj;
                $venc->modify('+' . ($i - 1) . ' months');
                $venc->setDate((int) $venc->format('Y'), (int) $venc->format('n'), min($diaVenc, (int) $venc->format('t')));

                DB::table('parcelas')->insert([
                    'matricula_financeira_id' => $matriculaId,
                    'aluno_id' => $alunoId,
                    'numero_parcela' => $i,
                    'valor_original' => $valorParcelaOriginal,
                    'desconto_aplicado' => $descontoPorParcela,
                    'valor_final' => $valorFinalParcela,
                    'data_vencimento' => $venc->format('Y-m-d'),
                ]);
            }

            return [
                'matricula_id' => $matriculaId,
                'parcelas_geradas' => $numParcelas,
                'valor_parcela' => $valorFinalParcela,
            ];
        });
    }

    /**
     * Verifica se o aluno já tem uma matrícula financeira ativa no ano letivo informado.
     */
    public function jaMatriculado(int $alunoId, string $anoLetivo): bool
    {
        return DB::table('matriculas_financeiras')
            ->where('aluno_id', $alunoId)
            ->where('ano_letivo', $anoLetivo)
            ->where('status', 'ativa')
            ->exists();
    }
}
