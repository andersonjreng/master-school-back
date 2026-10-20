<?php

namespace App\Services\Financeiro\Asaas;

use App\Services\Financeiro\FinanceiroConfigService;
use App\Services\Financeiro\FinanceiroHelperService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Parcela ↔ "payment" do Asaas (boleto com Pix).
 *
 * - Uma cobrança ativa por parcela (índice único em asaas_cobrancas).
 * - Multa e juros vão na cobrança, então o próprio boleto/Pix já cobra o valor
 *   certo depois do vencimento. Nossa config guarda juros AO DIA; o Asaas
 *   recebe juros AO MÊS (× 30).
 * - O Asaas não aceita vencimento no passado: parcela já vencida é emitida com
 *   um vencimento novo, e o valor pode incluir a multa/juros devidos até hoje.
 * - Antes de criar, procuramos no Asaas uma cobrança ativa com a mesma
 *   referência (parcela-{id}) — se uma tentativa anterior deu timeout depois
 *   de criar lá, reaproveitamos em vez de duplicar o boleto.
 */
class AsaasCobrancaService
{
    /** Status do Asaas → texto da tela. */
    public const STATUS = [
        'PENDING'           => 'Aguardando pagamento',
        'OVERDUE'           => 'Vencida',
        'CONFIRMED'         => 'Paga (compensando)',
        'RECEIVED'          => 'Paga',
        'RECEIVED_IN_CASH'  => 'Recebida fora do Asaas',
        'REFUNDED'          => 'Estornada',
        'REFUND_REQUESTED'  => 'Estorno solicitado',
        'REFUND_IN_PROGRESS' => 'Estorno em andamento',
        'DELETED'           => 'Cancelada',
    ];

    public function __construct(
        private AsaasClient $api,
        private AsaasConfigService $asaasConfig,
        private AsaasClienteService $clientes,
        private FinanceiroConfigService $config,
        private FinanceiroHelperService $helper,
    ) {
    }

    /**
     * Parcelas em aberto, sem cobrança ativa, que vencem até hoje + N dias
     * (as vencidas entram também). Cada uma diz se está pronta ou o que falta.
     */
    public function candidatas(int $dias): array
    {
        $limite = Carbon::today()->addDays($dias)->toDateString();
        $hoje = Carbon::today()->toDateString();
        $configEncargos = $this->helper->carregarConfiguracoes();

        $rows = $this->consultaParcelas()
            ->whereIn('p.status', ['pendente', 'vencido'])
            ->where('p.data_vencimento', '<=', $limite)
            ->whereNotExists(fn ($q) => $q->from('asaas_cobrancas as c')->whereColumn('c.parcela_id', 'p.id')->where('c.ativa', 1))
            ->orderBy('p.data_vencimento')->orderBy('aluno')
            ->get();

        $pagadores = $this->pagadoresDosAlunos($rows->pluck('aluno_id')->unique()->all());

        return $rows->map(function ($p) use ($pagadores, $hoje, $configEncargos) {
            $pag = $pagadores[$p->aluno_id] ?? null;
            $vencida = $p->data_vencimento < $hoje;
            $encargos = $vencida
                ? $this->helper->calcularEncargos($configEncargos, $p->data_vencimento, (float) $p->valor_final, $hoje)
                : ['multa_valor' => 0.0, 'juros_valor' => 0.0];

            $pendencia = match (true) {
                !$pag                         => 'aluno sem responsável vinculado',
                (bool) $pag['bloqueios']      => 'responsável com cadastro incompleto: ' . implode(', ', $pag['bloqueios']),
                default                       => null,
            };

            return [
                'parcela_id'      => (int) $p->id,
                'aluno_id'        => (int) $p->aluno_id,
                'aluno'           => $p->aluno,
                'descricao'       => "Parcela {$p->numero_parcela}/{$p->numero_parcelas} — {$p->plano_nome}",
                'valor'           => (float) $p->valor_final,
                'encargos'        => round($encargos['multa_valor'] + $encargos['juros_valor'], 2),
                'vencimento'      => $p->data_vencimento,
                'vencida'         => $vencida,
                'responsavel_id'  => $pag['responsavel_id'] ?? null,
                'responsavel'     => $pag['nome'] ?? null,
                'no_asaas'        => (bool) ($pag['customer_id'] ?? null),
                'pendencia'       => $pendencia,
            ];
        })->values()->all();
    }

    /**
     * Emite a cobrança de uma parcela. $novoVencimento é obrigatório se a
     * parcela já venceu; $incluirEncargos soma multa+juros devidos até hoje.
     *
     * @throws AsaasException|\DomainException
     */
    public function emitir(int $parcelaId, ?string $novoVencimento, bool $incluirEncargos, int $usuarioId): array
    {
        $p = $this->consultaParcelas()->where('p.id', $parcelaId)->first();
        if (!$p) {
            throw new \DomainException('Parcela não encontrada.');
        }
        if (!in_array($p->status, ['pendente', 'vencido'], true)) {
            throw new \DomainException('Só parcelas pendentes ou vencidas recebem cobrança.');
        }
        if (DB::table('asaas_cobrancas')->where('parcela_id', $parcelaId)->where('ativa', 1)->exists()) {
            throw new \DomainException('Esta parcela já tem cobrança ativa no Asaas.');
        }

        $hoje = Carbon::today()->toDateString();
        $vencida = $p->data_vencimento < $hoje;
        $valor = (float) $p->valor_final;
        $vencimento = $p->data_vencimento;

        if ($vencida) {
            if (!$novoVencimento || $novoVencimento < $hoje) {
                throw new \DomainException('Parcela vencida: informe um novo vencimento a partir de hoje.');
            }
            $vencimento = $novoVencimento;
            if ($incluirEncargos) {
                $enc = $this->helper->calcularEncargos($this->helper->carregarConfiguracoes(), $p->data_vencimento, $valor, $hoje);
                $valor = round($valor + $enc['multa_valor'] + $enc['juros_valor'], 2);
            }
        }

        $pag = $this->pagadoresDosAlunos([$p->aluno_id])[$p->aluno_id] ?? null;
        if (!$pag) {
            throw new \DomainException('O aluno não tem responsável vinculado.');
        }
        // Garante o cliente no Asaas (cria/atualiza na hora se ainda não foi).
        $customerId = $this->clientes->sincronizar($pag['responsavel_id']);

        $ref = "parcela-$parcelaId";
        $payment = $this->cobrancaExistente($ref, $customerId)
            ?? $this->api->post('/payments', $this->payload($p, $customerId, $valor, $vencimento, $ref));

        $linha = $this->linhaDigitavel($payment['id']);

        $id = DB::table('asaas_cobrancas')->insertGetId([
            'parcela_id'      => $parcelaId,
            'ambiente'        => $this->asaasConfig->ambiente(),
            'payment_id'      => $payment['id'],
            'customer_id'     => $customerId,
            'status'          => $payment['status'] ?? 'PENDING',
            'valor'           => $payment['value'] ?? $valor,
            'vencimento'      => $payment['dueDate'] ?? $vencimento,
            'invoice_url'     => $payment['invoiceUrl'] ?? null,
            'bank_slip_url'   => $payment['bankSlipUrl'] ?? null,
            'nosso_numero'    => $linha['nossoNumero'] ?? $payment['nossoNumero'] ?? null,
            'linha_digitavel' => $linha['identificationField'] ?? null,
            'criado_por'      => $usuarioId,
        ]);

        return ['cobranca_id' => $id, 'payment_id' => $payment['id'], 'valor' => (float) ($payment['value'] ?? $valor), 'vencimento' => $payment['dueDate'] ?? $vencimento];
    }

    /** Emite várias, sem parar no primeiro erro. */
    public function emitirVarias(array $parcelaIds, ?string $novoVencimento, bool $incluirEncargos, int $usuarioId): array
    {
        $resultado = [];
        foreach (array_unique(array_map('intval', $parcelaIds)) as $id) {
            try {
                $resultado[] = ['parcela_id' => $id, 'ok' => true] + $this->emitir($id, $novoVencimento, $incluirEncargos, $usuarioId);
            } catch (AsaasException | \DomainException $e) {
                $resultado[] = ['parcela_id' => $id, 'ok' => false, 'erro' => $e->getMessage()];
            }
        }
        return $resultado;
    }

    /**
     * Emissão automática (rotina diária): emite as parcelas que vencem nos
     * próximos N dias e ainda não têm cobrança.
     *
     * Ficam de fora, para alguém decidir na Central: parcelas já vencidas (o
     * Asaas exige um vencimento novo, e escolher a data é decisão da escola)
     * e as de responsável com cadastro incompleto.
     *
     * @return array{emitidas: int, falhas: int, pendencias: int, vencidas: int, resultado: array}
     */
    public function emitirAutomaticamente(int $limite = 300): array
    {
        $dias = $this->config->todas()['remessa_dias_antecedencia'];
        $candidatas = collect($this->candidatas($dias));

        $prontas = $candidatas->filter(fn ($c) => !$c['pendencia'] && !$c['vencida'])->take($limite);
        $resultado = $prontas->isEmpty() ? [] : $this->emitirVarias($prontas->pluck('parcela_id')->all(), null, false, 0);

        $ok = count(array_filter($resultado, fn ($r) => $r['ok']));
        return [
            'emitidas'   => $ok,
            'falhas'     => count($resultado) - $ok,
            'pendencias' => $candidatas->filter(fn ($c) => (bool) $c['pendencia'])->count(),
            'vencidas'   => $candidatas->filter(fn ($c) => !$c['pendencia'] && $c['vencida'])->count(),
            'resultado'  => $resultado,
        ];
    }

    /**
     * Cancela a cobrança no Asaas (o boleto/Pix deixa de ser pagável) e libera
     * a parcela para uma nova emissão. Cobrança já paga não pode ser cancelada.
     *
     * @throws AsaasException|\DomainException
     */
    public function cancelar(int $cobrancaId): void
    {
        $c = DB::table('asaas_cobrancas')->where('id', $cobrancaId)->first();
        if (!$c || !$c->ativa) {
            throw new \DomainException('Cobrança não encontrada ou já cancelada.');
        }
        if (in_array($c->status, ['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'], true)) {
            throw new \DomainException('Cobrança já paga não pode ser cancelada.');
        }

        try {
            $this->api->delete("/payments/{$c->payment_id}");
        } catch (AsaasException $e) {
            if ($e->httpStatus !== 404) { // já removida no painel do Asaas → só reflete aqui
                throw $e;
            }
        }

        DB::table('asaas_cobrancas')->where('id', $cobrancaId)->update([
            'status' => 'DELETED', 'ativa' => 0, 'cancelado_em' => now(),
        ]);
    }

    /**
     * QR Code Pix (imagem base64 + copia e cola) da cobrança ativa da parcela.
     *
     * @throws AsaasException|\DomainException
     */
    public function pix(int $parcelaId): array
    {
        $c = DB::table('asaas_cobrancas')
            ->where('parcela_id', $parcelaId)->where('ativa', 1)
            ->where('ambiente', $this->asaasConfig->ambiente())
            ->whereIn('status', ['PENDING', 'OVERDUE'])
            ->first();
        if (!$c) {
            throw new \DomainException('Parcela sem cobrança em aberto no Asaas.');
        }
        $r = $this->api->get("/payments/{$c->payment_id}/pixQrCode");

        return [
            'imagem_base64' => $r['encodedImage'] ?? null,
            'copia_e_cola'  => $r['payload'] ?? null,
            'expira_em'     => $r['expirationDate'] ?? null,
        ];
    }

    /** Cobranças emitidas (a ativa de cada parcela + histórico), mais recentes primeiro. */
    public function listar(?string $situacao, ?string $busca): array
    {
        $q = DB::table('asaas_cobrancas as c')
            ->join('parcelas as p', 'p.id', '=', 'c.parcela_id')
            ->join('alunos as a', 'a.id', '=', 'p.aluno_id')
            ->join('usuarios as ua', 'ua.id', '=', 'a.usuario_id')
            ->leftJoin('matriculas_financeiras as mf', 'mf.id', '=', 'p.matricula_financeira_id')
            ->leftJoin('planos_pagamento as pp', 'pp.id', '=', 'mf.plano_id')
            ->leftJoin('asaas_clientes as ac', function ($j) {
                $j->on('ac.customer_id', '=', 'c.customer_id');
            })
            ->leftJoin('responsaveis as r', 'r.id', '=', 'ac.responsavel_id')
            ->leftJoin('usuarios as ur', 'ur.id', '=', 'r.usuario_id')
            ->where('c.ambiente', $this->asaasConfig->ambiente());

        match ($situacao) {
            'abertas'    => $q->where('c.ativa', 1)->whereIn('c.status', ['PENDING', 'OVERDUE']),
            'vencidas'   => $q->where('c.ativa', 1)->where('c.status', 'OVERDUE'),
            'pagas'      => $q->whereIn('c.status', ['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH']),
            'canceladas' => $q->where('c.ativa', 0),
            default      => null,
        };
        if ($busca = trim((string) $busca)) {
            $q->where(fn ($w) => $w->where('ua.nome_completo', 'like', "%$busca%")->orWhere('ur.nome_completo', 'like', "%$busca%"));
        }

        return $q->orderByDesc('c.criado_em')->limit(500)->get([
            'c.id', 'c.parcela_id', 'c.payment_id', 'c.status', 'c.valor', 'c.vencimento', 'c.invoice_url', 'c.bank_slip_url',
            'c.linha_digitavel', 'c.valor_pago', 'c.data_pagamento', 'c.ativa', 'c.criado_em', 'c.cancelado_em', 'c.criado_por',
            'ua.nome_completo as aluno', 'ur.nome_completo as responsavel',
            'p.numero_parcela', 'pp.numero_parcelas', 'pp.nome as plano_nome', 'p.data_vencimento as vencimento_parcela',
        ])->map(fn ($c) => (array) $c + [
            'status_label' => self::STATUS[$c->status] ?? $c->status,
            'automatica'   => (int) $c->criado_por === 0,
        ])->all();
    }

    /** Contadores para o topo da tela. */
    public function resumo(int $dias): array
    {
        $ambiente = $this->asaasConfig->ambiente();
        $base = fn () => DB::table('asaas_cobrancas')->where('ambiente', $ambiente);
        $candidatas = $this->candidatas($dias);

        return [
            'para_emitir'   => count(array_filter($candidatas, fn ($c) => !$c['pendencia'])),
            'com_pendencia' => count(array_filter($candidatas, fn ($c) => (bool) $c['pendencia'])),
            'abertas'       => $base()->where('ativa', 1)->whereIn('status', ['PENDING', 'OVERDUE'])->count(),
            'valor_aberto'  => (float) $base()->where('ativa', 1)->whereIn('status', ['PENDING', 'OVERDUE'])->sum('valor'),
            'vencidas'      => $base()->where('ativa', 1)->where('status', 'OVERDUE')->count(),
            'pagas_mes'     => $base()->whereIn('status', ['CONFIRMED', 'RECEIVED'])->where('data_pagamento', '>=', Carbon::today()->startOfMonth())->count(),
        ];
    }

    private function payload(object $p, string $customerId, float $valor, string $vencimento, string $ref): array
    {
        $c = $this->config->todas();

        return [
            'customer'          => $customerId,
            'billingType'       => 'BOLETO', // boleto com QR Code Pix
            'value'             => $valor,
            'dueDate'           => $vencimento,
            'description'       => mb_substr("{$p->aluno} — Parcela {$p->numero_parcela}/{$p->numero_parcelas} — {$p->plano_nome} ({$p->ano_letivo})", 0, 500),
            'externalReference' => $ref,
            'fine'              => ['value' => (float) $c['multa_percentual'], 'type' => 'PERCENTAGE'],
            'interest'          => ['value' => round((float) $c['juros_diario_percentual'] * 30, 4)],
        ];
    }

    /** Cobrança ainda válida no Asaas com a mesma referência (tentativa anterior que deu timeout). */
    private function cobrancaExistente(string $ref, string $customerId): ?array
    {
        $lista = $this->api->get('/payments', ['externalReference' => $ref, 'customer' => $customerId, 'limit' => 10])['data'] ?? [];
        foreach ($lista as $pay) {
            if (empty($pay['deleted']) && in_array($pay['status'] ?? '', ['PENDING', 'OVERDUE'], true)
                && !DB::table('asaas_cobrancas')->where('payment_id', $pay['id'])->exists()) {
                return $pay;
            }
        }
        return null;
    }

    private function linhaDigitavel(string $paymentId): array
    {
        try {
            return $this->api->get("/payments/$paymentId/identificationField");
        } catch (AsaasException) {
            return []; // não impede a emissão; a fatura (invoiceUrl) tem o boleto completo
        }
    }

    private function consultaParcelas()
    {
        return DB::table('parcelas as p')
            ->join('alunos as a', 'a.id', '=', 'p.aluno_id')
            ->join('usuarios as ua', 'ua.id', '=', 'a.usuario_id')
            ->join('matriculas_financeiras as mf', 'mf.id', '=', 'p.matricula_financeira_id')
            ->join('planos_pagamento as pp', 'pp.id', '=', 'mf.plano_id')
            ->select([
                'p.id', 'p.aluno_id', 'p.numero_parcela', 'p.valor_final', 'p.data_vencimento', 'p.status',
                'ua.nome_completo as aluno', 'pp.nome as plano_nome', 'pp.numero_parcelas', 'mf.ano_letivo',
            ]);
    }

    /** aluno_id → pagador (dados da lista de pagadores do AsaasClienteService). */
    private function pagadoresDosAlunos(array $alunoIds): array
    {
        if (!$alunoIds) {
            return [];
        }
        $porResponsavel = collect($this->clientes->pagadores())->keyBy('responsavel_id');

        $mapa = [];
        $vinculos = DB::table('aluno_responsavel')->whereIn('aluno_id', $alunoIds)
            ->orderBy('aluno_id')->orderByDesc('principal')->orderBy('responsavel_id')->get();
        foreach ($vinculos as $v) {
            if (!isset($mapa[$v->aluno_id]) && $porResponsavel->has($v->responsavel_id)) {
                $pag = $porResponsavel[$v->responsavel_id];
                $mapa[$v->aluno_id] = [
                    'responsavel_id' => $pag['responsavel_id'],
                    'nome'           => $pag['nome'],
                    'customer_id'    => $pag['customer_id'],
                    'bloqueios'      => $pag['bloqueios'],
                ];
            }
        }
        return $mapa;
    }
}
