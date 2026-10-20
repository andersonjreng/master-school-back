<?php

namespace App\Services\Financeiro;

use Illuminate\Support\Facades\DB;

/**
 * Porta só cancelarMatriculaFinanceira() de api/financeiro/matricula_helper.php —
 * dependência direta de AlunoController::inativar() (inativar um aluno cancela
 * a matrícula financeira dele). O restante do helper (criarMatriculaFinanceira,
 * alunoJaMatriculado) pertence ao módulo financeiro em si, que ainda não foi
 * migrado — fica pra quando chegar a vez dele.
 */
class CancelamentoMatriculaFinanceiraService
{
    /**
     * @return array{modo: string, parcelas_afetadas: int}
     */
    public function cancelar(
        int $matriculaId,
        string $motivo,
        ?string $observacao,
        bool $perdoarVencidas,
        ?int $usuarioIdLogado,
    ): array {
        $mf = DB::table('matriculas_financeiras')->where('id', $matriculaId)->first(['id', 'status']);

        if (!$mf) {
            throw new \Exception('Matrícula financeira não encontrada.');
        }
        if ($mf->status !== 'ativa') {
            throw new \Exception('Esta matrícula já está ' . $mf->status . '.');
        }

        $temBaixa = DB::table('baixas_pagamento as b')
            ->join('parcelas as p', 'b.parcela_id', '=', 'p.id')
            ->where('p.matricula_financeira_id', $matriculaId)
            ->exists();

        // Boletos Asaas em aberto das parcelas que vão sair deixam de valer (antes
        // de mexer nas parcelas: se o Asaas recusar, a exceção aborta o cancelamento).
        // Sem baixa, todas as parcelas são apagadas; com baixa, só as canceláveis.
        $saem = !$temBaixa || $perdoarVencidas ? ['pendente', 'vencido'] : ['pendente'];
        $abertas = DB::table('parcelas')->where('matricula_financeira_id', $matriculaId)->whereIn('status', $saem)->pluck('id')->all();
        try {
            app(\App\Services\Financeiro\Asaas\AsaasCicloVidaService::class)->cancelarDasParcelas($abertas);
        } catch (\App\Services\Financeiro\Asaas\AsaasException $e) {
            throw new \Exception('Não foi possível cancelar os boletos no Asaas: ' . $e->getMessage());
        } catch (\DomainException $e) {
            throw new \Exception($e->getMessage());
        }

        if (!$temBaixa) {
            $parcelasAfetadas = DB::table('parcelas')->where('matricula_financeira_id', $matriculaId)->delete();
            DB::table('matriculas_financeiras')->where('id', $matriculaId)->delete();

            return ['modo' => 'excluida', 'parcelas_afetadas' => $parcelasAfetadas];
        }

        $statusCancelaveis = $perdoarVencidas ? ['pendente', 'vencido'] : ['pendente'];

        $parcelasAfetadas = DB::table('parcelas')
            ->where('matricula_financeira_id', $matriculaId)
            ->whereIn('status', $statusCancelaveis)
            ->update(['status' => 'cancelado']);

        $nota = "\n[Cancelada em " . now()->format('Y-m-d H:i') . " — motivo: $motivo"
            . ($observacao !== null && $observacao !== '' ? " — $observacao" : '') . ']';

        DB::statement(
            "UPDATE matriculas_financeiras SET status = 'cancelada', observacoes = CONCAT(COALESCE(observacoes, ''), ?) WHERE id = ?",
            [$nota, $matriculaId]
        );

        return ['modo' => 'cancelada', 'parcelas_afetadas' => $parcelasAfetadas];
    }
}
