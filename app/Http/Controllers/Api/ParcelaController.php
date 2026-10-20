<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\FinanceiroHelperService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/financeiro/{get_contas_receber,post_baixa,update_parcela,
 * post_renegociar,get_inadimplencia,get_minhas_parcelas}.php.
 */
class ParcelaController extends Controller
{
    public function __construct(
        private FinanceiroHelperService $helper,
        private LogSistemaService $log,
    ) {
    }

    public function contasReceber(Request $request): JsonResponse
    {
        DB::statement("UPDATE parcelas SET status = 'vencido' WHERE status = 'pendente' AND data_vencimento < CURDATE()");

        $query = DB::table('parcelas as p')
            ->join('matriculas_financeiras as mf', 'p.matricula_financeira_id', '=', 'mf.id')
            ->join('planos_pagamento as pp', 'mf.plano_id', '=', 'pp.id')
            ->join('alunos as a', 'p.aluno_id', '=', 'a.id')
            ->join('usuarios as u', 'a.usuario_id', '=', 'u.id')
            ->select([
                'p.id', 'p.aluno_id', 'u.nome_completo as aluno_nome', 'p.numero_parcela',
                'pp.numero_parcelas as total_parcelas', 'pp.nome as plano_nome',
                'p.valor_original', 'p.desconto_aplicado', 'p.valor_final', 'p.data_vencimento', 'p.status',
                'p.data_pagamento', 'p.forma_pagamento', 'p.valor_pago', 'p.observacoes', 'p.matricula_financeira_id',
                'mf.ano_letivo',
                DB::raw('(SELECT r.numero_recibo FROM recibos r WHERE r.parcela_id = p.id LIMIT 1) AS numero_recibo'),
                // Último boleto emitido para a parcela (modo Sicoob).
                'bb.status as boleto_status', 'bb.nosso_numero as boleto_nosso_numero',
                'bb.ultima_ocorrencia_descricao as boleto_ocorrencia', 'bb.ultima_ocorrencia_em as boleto_ocorrencia_em',
                DB::raw('(SELECT MAX(t.nome_turma) FROM turma_aluno ta JOIN turmas t ON t.id = ta.turma_id WHERE ta.aluno_id = p.aluno_id AND t.ano_letivo = mf.ano_letivo) AS turma'),
            ])
            ->leftJoin('boletos_bancarios as bb', 'bb.id', '=', DB::raw('(SELECT MAX(b2.id) FROM boletos_bancarios b2 WHERE b2.parcela_id = p.id)'))
            ->orderBy('p.data_vencimento')
            ->orderBy('u.nome_completo');

        if ($alunoId = $request->query('aluno_id')) {
            $query->where('p.aluno_id', (int) $alunoId);
        }
        if ($turmaId = $request->query('turma_id')) {
            $turmaIdInt = (int) $turmaId;
            $query->whereExists(function ($sub) use ($turmaIdInt) {
                $sub->select(DB::raw(1))->from('turma_aluno as ta')
                    ->whereColumn('ta.aluno_id', 'p.aluno_id')->where('ta.turma_id', $turmaIdInt);
            });
        }
        if (($status = $request->query('status')) && in_array($status, ['pendente', 'pago', 'vencido', 'cancelado', 'negociado'], true)) {
            $query->where('p.status', $status);
        }
        if ($mesInicio = $request->query('mes_inicio')) {
            $query->where('p.data_vencimento', '>=', $mesInicio);
        }
        if ($mesFim = $request->query('mes_fim')) {
            $query->where('p.data_vencimento', '<=', $mesFim);
        }

        $config = $this->helper->carregarConfiguracoes();
        $encargosZero = [
            'dias_atraso' => 0, 'dias_efetivos' => 0, 'multa_valor' => 0.0, 'juros_valor' => 0.0,
            'total_encargos' => 0.0, 'valor_com_encargos' => 0.0,
            'config_multa_pct' => $config['multa_pct'], 'config_juros_dia' => $config['juros_dia'], 'config_carencia' => $config['carencia'],
        ];

        $rows = $query->get();

        // Cobrança Asaas ativa de cada parcela (para a tela avisar antes de baixa/edição).
        $asaas = collect();
        if (DB::getSchemaBuilder()->hasTable('asaas_cobrancas') && $rows->isNotEmpty()) {
            $asaas = DB::table('asaas_cobrancas')
                ->whereIn('parcela_id', $rows->pluck('id'))
                ->where('ativa', 1)
                ->where('ambiente', app(\App\Services\Financeiro\Asaas\AsaasConfigService::class)->ambiente())
                ->get(['parcela_id', 'status', 'valor', 'vencimento', 'invoice_url'])
                ->keyBy('parcela_id');
        }

        $parcelas = $rows->map(function ($row) use ($config, $encargosZero, $asaas) {
            $arr = (array) $row;
            $c = $asaas[$row->id] ?? null;
            $arr['asaas'] = $c ? [
                'status' => $c->status,
                'status_label' => \App\Services\Financeiro\Asaas\AsaasCobrancaService::STATUS[$c->status] ?? $c->status,
                'valor' => (float) $c->valor,
                'vencimento' => $c->vencimento,
                'invoice_url' => $c->invoice_url,
            ] : null;
            $arr['valor_original'] = (float) $row->valor_original;
            $arr['desconto_aplicado'] = (float) $row->desconto_aplicado;
            $arr['valor_final'] = (float) $row->valor_final;
            $arr['valor_pago'] = $row->valor_pago !== null ? (float) $row->valor_pago : null;

            if ($row->status === 'vencido') {
                $arr = array_merge($arr, $this->helper->calcularEncargos($config, $row->data_vencimento, (float) $row->valor_final));
            } else {
                $arr = array_merge($arr, $encargosZero);
                $arr['valor_com_encargos'] = $arr['valor_final'];
            }

            return $arr;
        })->values();

        return response()->json(['success' => true, 'count' => $parcelas->count(), 'data' => $parcelas]);
    }

    public function baixa(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);

        $parcelaId = (int) $request->input('parcela_id', 0);
        $valorPago = $request->input('valor_pago');
        $dataPagamento = $request->input('data_pagamento');
        $forma = $request->input('forma_pagamento');
        $observacoes = trim((string) $request->input('observacoes', ''));

        $formasValidas = ['dinheiro', 'pix', 'transferencia', 'cartao_debito', 'cartao_credito'];

        if (!$parcelaId || $valorPago === null || !$dataPagamento || !in_array($forma, $formasValidas, true)) {
            return response()->json([
                'error' => 'Campos inválidos.',
                'message' => 'parcela_id, valor_pago, data_pagamento e forma_pagamento válida são obrigatórios.',
            ], 400);
        }

        $parcela = DB::table('parcelas')->where('id', $parcelaId)->first(['id', 'aluno_id', 'status']);
        if (!$parcela) {
            return response()->json(['error' => 'Parcela não encontrada.'], 404);
        }
        if ($parcela->status === 'pago') {
            return response()->json(['error' => 'Parcela já está paga.'], 409);
        }
        if (in_array($parcela->status, ['cancelado', 'negociado'], true)) {
            return response()->json(['error' => 'Parcela cancelada ou negociada não pode receber baixa.'], 409);
        }

        // Parcela com boleto Asaas em aberto: a baixa avisa o Asaas ("recebido em
        // dinheiro") na mesma transação — se o Asaas recusar, a baixa é desfeita,
        // senão a família ainda conseguiria pagar o boleto/Pix.
        $asaas = app(\App\Services\Financeiro\Asaas\AsaasCicloVidaService::class);
        try {
            $asaas->exigirSemPagamentoPendente($parcelaId);
            $resultado = DB::transaction(function () use ($asaas, $parcelaId, $valorPago, $dataPagamento, $forma, $observacoes, $usuarioIdLogado) {
                $r = $this->helper->aplicarBaixaParcela($parcelaId, (float) $valorPago, $dataPagamento, $forma, $observacoes, $usuarioIdLogado);
                $asaas->aposBaixaManual($parcelaId, (int) $r['baixa_id'], (float) $valorPago, $dataPagamento);
                return $r;
            });
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\App\Services\Financeiro\Asaas\AsaasException $e) {
            return response()->json(['error' => 'A baixa não foi registrada: não foi possível marcar o boleto como pago no Asaas. ' . $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Erro ao registrar baixa.', 'message' => $e->getMessage()], 500);
        }

        $this->log->registrar(
            $request, $usuarioIdLogado, $jwtUser->nome ?? null, 'BAIXA_PARCELA',
            '/api/financeiro/parcelas/baixa', 'POST',
            "Baixa da parcela #$parcelaId (aluno #{$parcela->aluno_id}) — R$ $valorPago via $forma. Recibo: {$resultado['numero_recibo']}", 201,
            ['parcela_id' => $parcelaId, 'baixa_id' => $resultado['baixa_id'], 'recibo_id' => $resultado['recibo_id'], 'numero_recibo' => $resultado['numero_recibo']]
        );

        return response()->json([
            'success' => true,
            'message' => 'Pagamento registrado e recibo gerado.',
            'baixa_id' => $resultado['baixa_id'],
            'recibo_id' => $resultado['recibo_id'],
            'numero_recibo' => $resultado['numero_recibo'],
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $id = (int) $request->input('id', 0);

        if (!$id) {
            return response()->json(['error' => 'ID da parcela é obrigatório.'], 400);
        }

        $parcela = DB::table('parcelas')->where('id', $id)->first();
        if (!$parcela) {
            return response()->json(['error' => 'Parcela não encontrada.'], 404);
        }
        if ($parcela->status === 'pago') {
            return response()->json(['error' => 'Parcela já paga não pode ser editada.'], 409);
        }

        $valorOriginal = $request->filled('valor_original') ? (float) $request->input('valor_original') : (float) $parcela->valor_original;
        $desconto = $request->filled('desconto_aplicado') ? (float) $request->input('desconto_aplicado') : (float) $parcela->desconto_aplicado;
        $dataVencimento = $request->input('data_vencimento', $parcela->data_vencimento);
        $observacoes = $request->filled('observacoes') ? trim($request->input('observacoes')) : $parcela->observacoes;

        $valorFinal = round($valorOriginal - $desconto, 2);
        if ($valorFinal < 0) {
            return response()->json(['error' => 'Desconto maior que o valor da parcela.'], 400);
        }

        // Boleto Asaas em aberto acompanha o novo valor/vencimento (antes de gravar:
        // se o Asaas recusar, a parcela não muda).
        try {
            app(\App\Services\Financeiro\Asaas\AsaasCicloVidaService::class)->aoEditar($id, $valorFinal, (string) $dataVencimento);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\App\Services\Financeiro\Asaas\AsaasException $e) {
            return response()->json(['error' => 'A parcela não foi alterada: o Asaas recusou a mudança no boleto. ' . $e->getMessage()], 422);
        }

        DB::table('parcelas')->where('id', $id)->update([
            'valor_original' => $valorOriginal,
            'desconto_aplicado' => $desconto,
            'valor_final' => $valorFinal,
            'data_vencimento' => $dataVencimento,
            'observacoes' => $observacoes,
        ]);

        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'EDICAO_PARCELA',
            '/api/financeiro/parcelas', 'PUT',
            "Parcela #$id editada — novo valor: R$ $valorFinal, venc: $dataVencimento", 200,
            ['parcela_id' => $id, 'valor_final' => $valorFinal]
        );

        return response()->json(['success' => true, 'message' => 'Parcela atualizada com sucesso.', 'valor_final' => $valorFinal]);
    }

    public function renegociar(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);

        $parcelaId = (int) $request->input('parcela_id', 0);
        $novoVencimento = $request->input('novo_vencimento');
        $novoValorInput = $request->input('novo_valor');
        $observacoes = trim((string) $request->input('observacoes', ''));

        if (!$parcelaId || !$novoVencimento) {
            return response()->json(['error' => 'parcela_id e novo_vencimento são obrigatórios.'], 400);
        }

        $parcela = DB::table('parcelas')->where('id', $parcelaId)->first();
        if (!$parcela) {
            return response()->json(['error' => 'Parcela não encontrada.'], 404);
        }
        if (!in_array($parcela->status, ['vencido', 'pendente'], true)) {
            return response()->json(['error' => 'Apenas parcelas pendentes ou vencidas podem ser renegociadas.'], 409);
        }

        $valorNova = $novoValorInput !== null ? (float) $novoValorInput : (float) $parcela->valor_final;

        // O boleto Asaas da parcela antiga deixa de valer; a nova é emitida depois, na Central Asaas.
        try {
            app(\App\Services\Financeiro\Asaas\AsaasCicloVidaService::class)->cancelarDasParcelas([$parcelaId]);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\App\Services\Financeiro\Asaas\AsaasException $e) {
            return response()->json(['error' => 'A renegociação não foi feita: não foi possível cancelar o boleto no Asaas. ' . $e->getMessage()], 422);
        }

        try {
            $novaId = DB::transaction(function () use ($parcela, $parcelaId, $observacoes, $novoVencimento, $valorNova) {
                $obsCancel = 'Renegociada em ' . date('d/m/Y') . '. ' . $observacoes;
                DB::table('parcelas')->where('id', $parcelaId)->update(['status' => 'negociado', 'observacoes' => $obsCancel]);

                $obsNova = "Renegociação da parcela #{$parcelaId}. " . $observacoes;

                return DB::table('parcelas')->insertGetId([
                    'matricula_financeira_id' => $parcela->matricula_financeira_id,
                    'aluno_id' => $parcela->aluno_id,
                    'numero_parcela' => $parcela->numero_parcela,
                    'valor_original' => $valorNova,
                    'desconto_aplicado' => 0,
                    'valor_final' => $valorNova,
                    'data_vencimento' => $novoVencimento,
                    'observacoes' => $obsNova,
                    'parcela_original_id' => $parcelaId,
                ]);
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Erro ao renegociar parcela.', 'message' => $e->getMessage()], 500);
        }

        $this->log->registrar(
            $request, $usuarioIdLogado, $jwtUser->nome ?? null, 'RENEGOCIACAO_PARCELA',
            '/api/financeiro/parcelas/renegociar', 'POST',
            "Parcela #$parcelaId renegociada. Nova parcela #$novaId — R$ $valorNova, venc: $novoVencimento", 201,
            ['parcela_original_id' => $parcelaId, 'nova_parcela_id' => $novaId]
        );

        return response()->json([
            'success' => true,
            'message' => 'Parcela renegociada com sucesso.',
            'parcela_original' => $parcelaId,
            'nova_parcela_id' => $novaId,
            'novo_vencimento' => $novoVencimento,
            'novo_valor' => $valorNova,
        ], 201);
    }

    public function inadimplencia(Request $request): JsonResponse
    {
        DB::statement("UPDATE parcelas SET status = 'vencido' WHERE status = 'pendente' AND data_vencimento < CURDATE()");

        $query = DB::table('parcelas as p')
            ->join('matriculas_financeiras as mf', 'p.matricula_financeira_id', '=', 'mf.id')
            ->join('planos_pagamento as pp', 'mf.plano_id', '=', 'pp.id')
            ->join('alunos as a', 'p.aluno_id', '=', 'a.id')
            ->join('usuarios as u', 'a.usuario_id', '=', 'u.id')
            ->where('p.status', 'vencido')
            ->select([
                'p.id', 'p.aluno_id', 'u.nome_completo as aluno_nome', 'pp.nome as plano_nome',
                'p.numero_parcela', 'pp.numero_parcelas as total_parcelas', 'p.valor_final', 'p.data_vencimento',
                DB::raw('DATEDIFF(CURDATE(), p.data_vencimento) AS dias_atraso'),
                'p.observacoes', 'mf.ano_letivo',
                DB::raw('(SELECT t.nome_turma FROM turma_aluno ta JOIN turmas t ON ta.turma_id = t.id WHERE ta.aluno_id = p.aluno_id ORDER BY t.id DESC LIMIT 1) AS turma_nome'),
                DB::raw('(SELECT u2.nome_completo FROM responsaveis r JOIN aluno_responsavel ar ON r.id = ar.responsavel_id JOIN usuarios u2 ON r.usuario_id = u2.id WHERE ar.aluno_id = p.aluno_id LIMIT 1) AS responsavel_nome'),
            ])
            ->orderBy('p.data_vencimento')
            ->orderBy('u.nome_completo');

        if ($turmaId = $request->query('turma_id')) {
            $turmaIdInt = (int) $turmaId;
            $query->whereExists(function ($sub) use ($turmaIdInt) {
                $sub->select(DB::raw(1))->from('turma_aluno as ta')
                    ->whereColumn('ta.aluno_id', 'p.aluno_id')->where('ta.turma_id', $turmaIdInt);
            });
        }
        if ($mesIni = $request->query('mes_inicio')) {
            $query->where('p.data_vencimento', '>=', $mesIni);
        }
        if ($mesFim = $request->query('mes_fim')) {
            $query->where('p.data_vencimento', '<=', $mesFim);
        }

        $config = $this->helper->carregarConfiguracoes();
        $totalInadimplente = 0.0;
        $totalComEncargos = 0.0;

        $parcelas = $query->get()->map(function ($row) use ($config, &$totalInadimplente, &$totalComEncargos) {
            $arr = (array) $row;
            $arr['valor_final'] = (float) $row->valor_final;
            $arr['status'] = 'vencido';
            $arr = array_merge($arr, $this->helper->calcularEncargos($config, $row->data_vencimento, (float) $row->valor_final));

            $totalInadimplente += $arr['valor_final'];
            $totalComEncargos += $arr['valor_com_encargos'];

            return $arr;
        })->values();

        return response()->json([
            'success' => true,
            'count' => $parcelas->count(),
            'total_inadimplente' => round($totalInadimplente, 2),
            'total_com_encargos' => round($totalComEncargos, 2),
            'data' => $parcelas,
        ]);
    }

    /**
     * Parcelas financeiras de um dependente, visíveis pelo próprio responsável.
     * Diferente de contasReceber() (uso administrativo, aceita qualquer aluno_id):
     * aqui o aluno_id é sempre validado contra os dependentes do responsável logado,
     * nunca confiando apenas no parâmetro recebido.
     */
    public function minhasParcelas(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);

        $alunoId = (int) $request->query('aluno_id', 0);
        if (!$alunoId) {
            return response()->json(['error' => 'aluno_id é obrigatório.'], 400);
        }

        if (!$this->ehDependente($usuarioIdLogado, $alunoId)) {
            return response()->json(['error' => 'Acesso negado. Este aluno não é seu dependente.'], 403);
        }

        DB::table('parcelas')
            ->where('status', 'pendente')
            ->where('data_vencimento', '<', now()->toDateString())
            ->where('aluno_id', $alunoId)
            ->update(['status' => 'vencido']);

        $rows = DB::table('parcelas as p')
            ->join('matriculas_financeiras as mf', 'p.matricula_financeira_id', '=', 'mf.id')
            ->join('planos_pagamento as pp', 'mf.plano_id', '=', 'pp.id')
            ->join('alunos as a', 'p.aluno_id', '=', 'a.id')
            ->join('usuarios as u', 'a.usuario_id', '=', 'u.id')
            ->where('p.aluno_id', $alunoId)
            ->orderBy('p.data_vencimento')
            ->select([
                'p.id', 'p.aluno_id', 'u.nome_completo as aluno_nome', 'p.numero_parcela',
                'pp.numero_parcelas as total_parcelas', 'pp.nome as plano_nome',
                'p.valor_original', 'p.desconto_aplicado', 'p.valor_final', 'p.data_vencimento', 'p.status',
                'p.data_pagamento', 'p.forma_pagamento', 'p.valor_pago', 'mf.ano_letivo',
                DB::raw('(SELECT r.numero_recibo FROM recibos r WHERE r.parcela_id = p.id LIMIT 1) AS numero_recibo'),
                'bb.status as boleto_status',
            ])
            ->leftJoin('boletos_bancarios as bb', 'bb.id', '=', DB::raw('(SELECT MAX(b2.id) FROM boletos_bancarios b2 WHERE b2.parcela_id = p.id)'))
            ->get();

        $modo = app(\App\Services\Financeiro\FinanceiroConfigService::class)->todas()['modo_cobranca'];

        // Asaas: cobrança ativa de cada parcela (fatura, boleto em PDF, linha digitável).
        $cobrancasAsaas = $modo === 'asaas'
            ? DB::table('asaas_cobrancas')
                ->whereIn('parcela_id', $rows->pluck('id'))
                ->where('ativa', 1)
                ->where('ambiente', app(\App\Services\Financeiro\Asaas\AsaasConfigService::class)->ambiente())
                ->get(['parcela_id', 'status', 'valor', 'vencimento', 'invoice_url', 'bank_slip_url', 'linha_digitavel'])
                ->keyBy('parcela_id')
            : collect();
        $config = $this->helper->carregarConfiguracoes();
        $encargosZero = [
            'dias_atraso' => 0, 'dias_efetivos' => 0, 'multa_valor' => 0.0, 'juros_valor' => 0.0,
            'total_encargos' => 0.0, 'valor_com_encargos' => 0.0,
        ];

        $parcelas = $rows->map(function ($row) use ($config, $encargosZero, $modo, $cobrancasAsaas) {
            $arr = (array) $row;
            $arr['valor_original'] = (float) $row->valor_original;
            $arr['desconto_aplicado'] = (float) $row->desconto_aplicado;
            $arr['valor_final'] = (float) $row->valor_final;
            $arr['valor_pago'] = $row->valor_pago !== null ? (float) $row->valor_pago : null;
            $aberta = in_array($row->status, ['pendente', 'vencido'], true);
            // Boleto que ainda pode ser pago (2ª via / linha digitável).
            $arr['boleto_disponivel'] = $modo === 'sicoob_arquivo' && $aberta
                && in_array($row->boleto_status, \App\Services\Financeiro\Sicoob\RemessaSicoobService::BOLETO_ATIVO, true);

            $arr['cobranca'] = null;
            $c = $cobrancasAsaas[$row->id] ?? null;
            if ($c && $aberta && in_array($c->status, ['PENDING', 'OVERDUE'], true)) {
                $arr['boleto_disponivel'] = true;
                $arr['cobranca'] = [
                    'valor'           => (float) $c->valor,
                    'vencimento'      => $c->vencimento,
                    'invoice_url'     => $c->invoice_url,
                    'bank_slip_url'   => $c->bank_slip_url,
                    'linha_digitavel' => $c->linha_digitavel,
                ];
            }

            if ($row->status === 'vencido') {
                $arr = array_merge($arr, $this->helper->calcularEncargos($config, $row->data_vencimento, (float) $row->valor_final));
            } else {
                $arr = array_merge($arr, $encargosZero);
                $arr['valor_com_encargos'] = $arr['valor_final'];
            }

            return $arr;
        })->values();

        return response()->json([
            'success' => true,
            'count' => $parcelas->count(),
            'modo_cobranca' => $modo,
            'encargos' => ['multa_pct' => $config['multa_pct'], 'juros_dia' => $config['juros_dia'], 'carencia' => $config['carencia']],
            'data' => $parcelas,
        ]);
    }

    /** 2ª via do boleto para o responsável (só de dependente dele). */
    public function meuBoleto(Request $request, int $id): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $alunoId = (int) DB::table('parcelas')->where('id', $id)->value('aluno_id');
        if (!$alunoId || !$this->ehDependente((int) ($jwtUser->id ?? 0), $alunoId)) {
            return response()->json(['error' => 'Parcela não encontrada.'], 404);
        }
        $visual = app(\App\Services\Financeiro\Sicoob\BoletoVisualService::class);
        try {
            return response()->json([
                'success' => true,
                'html' => $visual->gerarHtml($id),
                'linha_digitavel' => $visual->linhaDigitavel($id),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Boleto indisponível no momento. Fale com a secretaria da escola.'], 422);
        }
    }

    /**
     * Pix da cobrança Asaas de uma parcela do dependente. Buscado na hora: sem
     * chave Pix cadastrada na conta da escola, o QR Code vale só até 23:59 do dia.
     */
    public function meuPix(Request $request, int $id): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $alunoId = (int) DB::table('parcelas')->where('id', $id)->value('aluno_id');
        if (!$alunoId || !$this->ehDependente((int) ($jwtUser->id ?? 0), $alunoId)) {
            return response()->json(['error' => 'Parcela não encontrada.'], 404);
        }
        try {
            $pix = app(\App\Services\Financeiro\Asaas\AsaasCobrancaService::class)->pix($id);
        } catch (\App\Services\Financeiro\Asaas\AsaasException | \DomainException $e) {
            return response()->json(['error' => 'Pix indisponível no momento. Use o boleto ou fale com a secretaria.'], 422);
        }
        return response()->json(['success' => true, 'data' => $pix]);
    }

    private function ehDependente(int $usuarioId, int $alunoId): bool
    {
        return DB::table('responsaveis as r')
            ->join('aluno_responsavel as ar', 'r.id', '=', 'ar.responsavel_id')
            ->where('r.usuario_id', $usuarioId)
            ->where('ar.aluno_id', $alunoId)
            ->exists();
    }
}
