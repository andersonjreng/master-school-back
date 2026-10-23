<?php

namespace App\Services\Rematricula;

use App\Services\Financeiro\Asaas\AsaasCobrancaService;
use App\Services\Financeiro\CancelamentoMatriculaFinanceiraService;
use App\Services\Financeiro\ContratoFinanceiroService;
use App\Services\Financeiro\FinanceiroConfigService;
use App\Services\Financeiro\MatriculaFinanceiraService;
use App\Services\Notificacoes\NotificacaoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Rematrícula online.
 *
 * A escola abre uma campanha para o ano novo, diz para qual turma vai cada
 * turma atual e o que cobra na hora (1ª parcela, taxa ou nada). A família
 * confere os dados, escolhe o plano, aceita o contrato e paga; ou responde
 * que não vai renovar. O aceite gera na hora o contrato do ano novo (matrícula
 * financeira + parcelas); a rematrícula vira "confirmada" quando a parcela
 * cobrada na hora é paga (baixa manual ou Asaas) — ou já no aceite, se não
 * há cobrança.
 *
 * A rematrícula NÃO muda o aluno de turma: isso continua na virada de ano
 * (promoção), que usa o resultado daqui para já vir preenchida.
 */
class RematriculaService
{
    private ?bool $tabelas = null;

    public function __construct(
        private ContratoRematriculaService $contrato,
        private MatriculaFinanceiraService $matriculas,
        private ContratoFinanceiroService $contratos,
        private FinanceiroConfigService $financeiro,
        private NotificacaoService $notificacoes,
    ) {
    }

    public function disponivel(): bool
    {
        // Escola que ainda não rodou a migration: o resto do sistema segue normal.
        return $this->tabelas ??= Schema::hasTable('rematriculas');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Escola: campanhas
    // ═════════════════════════════════════════════════════════════════════

    public function campanhas(): array
    {
        return DB::table('rematricula_campanhas')->orderByDesc('ano_destino')->get()
            ->map(fn ($c) => $this->formatarCampanha($c) + ['resumo' => $this->resumo((int) $c->id)])
            ->all();
    }

    public function campanha(int $id): ?array
    {
        $c = DB::table('rematricula_campanhas')->where('id', $id)->first();
        if (!$c) {
            return null;
        }
        $turmas = DB::table('rematricula_turmas')->where('campanha_id', $id)->get(['turma_origem_id', 'turma_destino_id'])
            ->map(fn ($t) => ['turma_origem_id' => (int) $t->turma_origem_id, 'turma_destino_id' => (int) $t->turma_destino_id])->all();
        return $this->formatarCampanha($c) + ['contrato_modelo' => $c->contrato_modelo, 'turmas' => $turmas];
    }

    /** Valores iniciais para uma campanha nova: próximo ano, turmas sugeridas e o contrato padrão. */
    public function novaSugerida(): array
    {
        $anoAtual = (int) now()->year;
        $ano = $anoAtual + 1;
        return [
            'ano_destino'          => $ano,
            'inicio'               => now()->toDateString(),
            'fim'                  => now()->addDays(30)->toDateString(),
            'cobranca'             => 'primeira_parcela',
            'taxa_valor'           => null,
            'prazo_pagamento_dias' => 5,
            'desconto_tipo'        => null,
            'desconto_valor'       => null,
            'desconto_ate'         => null,
            'contrato_modelo'      => $this->contrato->modeloPadrao(),
            'turmas'               => $this->sugerirTurmas($ano),
        ];
    }

    /**
     * Turmas ativas do ano anterior com a turma sugerida do ano novo (série
     * seguinte, de preferência no mesmo turno). Sem sugestão = destino nulo
     * (ex.: última série que a escola oferece) e a escola escolhe.
     */
    public function sugerirTurmas(int $anoDestino): array
    {
        $origens = DB::table('turmas')->where('ano_letivo', $anoDestino - 1)->where('status', 'Ativa')
            ->orderBy('nome_turma')->get(['id', 'nome_turma', 'turno']);
        $destinos = DB::table('turmas')->where('ano_letivo', $anoDestino)->where('status', 'Ativa')
            ->get(['id', 'nome_turma', 'turno']);

        return $origens->map(function ($o) use ($destinos) {
            $candidatas = collect();
            foreach ($this->seriesSeguintes($o->nome_turma) as $proxima) {
                $candidatas = $destinos->filter(fn ($d) => $this->serie($d->nome_turma) === $proxima);
                if ($candidatas->isNotEmpty()) {
                    break;
                }
            }
            $escolhida = $candidatas->firstWhere('turno', $o->turno) ?? $candidatas->first();
            return ['turma_origem_id' => (int) $o->id, 'turma_destino_id' => $escolhida ? (int) $escolhida->id : null];
        })->all();
    }

    /** Turmas do ano anterior e do ano novo, para os selects da tela. */
    public function turmasDoAno(int $anoDestino): array
    {
        $lista = fn (int $ano) => DB::table('turmas')->where('ano_letivo', $ano)->where('status', 'Ativa')->orderBy('nome_turma')
            ->get(['id', 'nome_turma', 'turno'])
            ->map(fn ($t) => [
                'id' => (int) $t->id, 'nome' => $t->nome_turma, 'turno' => $t->turno,
                'alunos' => $this->alunosAtivosDaTurma((int) $t->id)->count(),
                'planos' => count($this->contratos->planosPorTurma([(int) $t->id])[(int) $t->id] ?? []),
            ])->all();
        return ['origem' => $lista($anoDestino - 1), 'destino' => $lista($anoDestino)];
    }

    /** @throws \DomainException */
    public function salvar(?int $id, array $d, int $usuarioId): int
    {
        $ano = (int) ($d['ano_destino'] ?? 0);
        $cobranca = $d['cobranca'] ?? 'primeira_parcela';
        $inicio = $d['inicio'] ?? null;
        $fim = $d['fim'] ?? null;
        $modelo = trim((string) ($d['contrato_modelo'] ?? ''));

        if ($ano < 2000) {
            throw new \DomainException('Informe o ano letivo da rematrícula.');
        }
        if (!$inicio || !$fim || $fim < $inicio) {
            throw new \DomainException('Informe o período: o fim não pode ser antes do início.');
        }
        if (!in_array($cobranca, ['primeira_parcela', 'taxa', 'nenhuma'], true)) {
            throw new \DomainException('Forma de cobrança inválida.');
        }
        $taxa = $cobranca === 'taxa' ? round((float) ($d['taxa_valor'] ?? 0), 2) : null;
        if ($cobranca === 'taxa' && $taxa <= 0) {
            throw new \DomainException('Informe o valor da taxa de matrícula.');
        }
        $prazo = max(1, min(60, (int) ($d['prazo_pagamento_dias'] ?? 5)));

        $descTipo = $d['desconto_tipo'] ?? null;
        $descValor = $descTipo ? round((float) ($d['desconto_valor'] ?? 0), 2) : null;
        $descAte = $descTipo ? ($d['desconto_ate'] ?? null) : null;
        if ($descTipo !== null) {
            if (!in_array($descTipo, ['percentual', 'valor'], true) || $descValor <= 0 || !$descAte) {
                throw new \DomainException('Desconto antecipado: informe o tipo, o valor e até quando vale.');
            }
            if ($descTipo === 'percentual' && $descValor > 100) {
                throw new \DomainException('O desconto não pode passar de 100%.');
            }
        }
        if ($modelo === '') {
            throw new \DomainException('O texto do contrato não pode ficar vazio.');
        }
        if ($desconhecidos = $this->contrato->camposDesconhecidos($modelo)) {
            throw new \DomainException('O contrato usa campos que não existem: {' . implode('}, {', $desconhecidos) . '}.');
        }

        $outra = DB::table('rematricula_campanhas')->where('ano_destino', $ano)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($outra) {
            throw new \DomainException("Já existe uma rematrícula para $ano.");
        }

        $turmas = collect($d['turmas'] ?? [])->filter(fn ($t) => !empty($t['turma_origem_id']) && !empty($t['turma_destino_id']));
        $destinosValidos = DB::table('turmas')->where('ano_letivo', $ano)->pluck('id')->map(fn ($v) => (int) $v)->all();
        foreach ($turmas as $t) {
            if (!in_array((int) $t['turma_destino_id'], $destinosValidos, true)) {
                throw new \DomainException("As turmas de destino precisam ser do ano $ano.");
            }
        }

        $dados = [
            'ano_destino' => $ano, 'inicio' => $inicio, 'fim' => $fim, 'cobranca' => $cobranca, 'taxa_valor' => $taxa,
            'prazo_pagamento_dias' => $prazo, 'desconto_tipo' => $descTipo, 'desconto_valor' => $descValor,
            'desconto_ate' => $descAte, 'contrato_modelo' => $modelo, 'atualizado_em' => now(),
        ];

        return DB::transaction(function () use ($id, $dados, $turmas, $usuarioId) {
            if ($id) {
                $atual = DB::table('rematricula_campanhas')->where('id', $id)->first(['id', 'ano_destino']);
                if (!$atual) {
                    throw new \DomainException('Rematrícula não encontrada.');
                }
                $temRespostas = DB::table('rematriculas')->where('campanha_id', $id)->exists();
                if ($temRespostas && (int) $atual->ano_destino !== $dados['ano_destino']) {
                    throw new \DomainException('Já há famílias que responderam: o ano não pode mudar.');
                }
                DB::table('rematricula_campanhas')->where('id', $id)->update($dados);
            } else {
                $id = DB::table('rematricula_campanhas')->insertGetId($dados + ['criado_por' => $usuarioId, 'status' => 'rascunho']);
            }
            DB::table('rematricula_turmas')->where('campanha_id', $id)->delete();
            foreach ($turmas->unique('turma_origem_id') as $t) {
                DB::table('rematricula_turmas')->insert([
                    'campanha_id' => $id, 'turma_origem_id' => (int) $t['turma_origem_id'], 'turma_destino_id' => (int) $t['turma_destino_id'],
                ]);
            }
            return (int) $id;
        });
    }

    /** @throws \DomainException */
    public function mudarStatus(int $id, string $status): void
    {
        $c = DB::table('rematricula_campanhas')->where('id', $id)->first();
        if (!$c) {
            throw new \DomainException('Rematrícula não encontrada.');
        }
        if ($status === 'aberta') {
            if (!DB::table('rematricula_turmas')->where('campanha_id', $id)->exists()) {
                throw new \DomainException('Defina para qual turma vai cada turma antes de abrir.');
            }
            $semPlano = DB::table('rematricula_turmas as rt')->join('turmas as t', 't.id', '=', 'rt.turma_destino_id')
                ->where('rt.campanha_id', $id)
                ->whereNotExists(fn ($q) => $q->from('plano_pagamento_turma as pt')->join('planos_pagamento as p', 'p.id', '=', 'pt.plano_id')
                    ->whereColumn('pt.turma_id', 'rt.turma_destino_id')->where('p.ativo', 1))
                ->pluck('t.nome_turma')->all();
            // O contrato do ano novo sempre precisa de um plano (mesmo sem cobrança na hora).
            if ($semPlano) {
                throw new \DomainException('Estas turmas do ano novo ainda não têm plano de pagamento: ' . implode(', ', $semPlano)
                    . '. Vincule em Financeiro → Contratos → Planos.');
            }
        }
        DB::table('rematricula_campanhas')->where('id', $id)->update(['status' => $status, 'atualizado_em' => now()]);
        if ($status === 'aberta') {
            $this->lembrar($id);
        }
    }

    public function excluir(int $id): void
    {
        if (DB::table('rematriculas')->where('campanha_id', $id)->whereIn('status', ['aguardando_pagamento', 'confirmada'])->exists()) {
            throw new \DomainException('Há rematrículas feitas nesta campanha. Encerre em vez de excluir.');
        }
        DB::table('rematricula_campanhas')->where('id', $id)->delete();
    }

    public function modeloPadrao(): array
    {
        return ['modelo' => $this->contrato->modeloPadrao(), 'campos' => ContratoRematriculaService::CAMPOS];
    }

    /** Prévia do contrato com dados de exemplo (tela de edição da escola). */
    public function previaModelo(array $d): string
    {
        $c = (object) [
            'ano_destino' => (int) ($d['ano_destino'] ?? now()->year + 1),
            'cobranca' => $d['cobranca'] ?? 'primeira_parcela',
            'taxa_valor' => (float) ($d['taxa_valor'] ?? 0),
            'prazo_pagamento_dias' => (int) ($d['prazo_pagamento_dias'] ?? 5),
        ];
        $campos = $this->contrato->campos($c, [
            'responsavel' => ['nome' => 'Maria da Silva (exemplo)', 'cpf' => '000.000.000-00', 'endereco' => 'Rua Exemplo', 'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'Cidade', 'uf' => 'UF', 'cep' => '00000-000'],
            'aluno' => (object) ['nome' => 'João da Silva (exemplo)', 'data_nascimento' => '2018-05-10'],
            'turma' => '3º Ano', 'plano' => (object) ['nome' => 'Mensalidade (exemplo)', 'numero_parcelas' => 12, 'dia_vencimento' => 10],
            'valor_parcela' => 500.0, 'valor_total' => 6000.0, 'desconto' => 'sem desconto', 'data' => now(),
        ]);
        return $this->contrato->montar((string) ($d['contrato_modelo'] ?? ''), $campos);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Escola: acompanhamento
    // ═════════════════════════════════════════════════════════════════════

    /** Painel: por turma de origem, cada aluno e a situação da rematrícula. */
    public function painel(int $id): ?array
    {
        $c = DB::table('rematricula_campanhas')->where('id', $id)->first();
        if (!$c) {
            return null;
        }
        $this->sincronizarPagamentos($id);

        $mapa = DB::table('rematricula_turmas as rt')
            ->join('turmas as o', 'o.id', '=', 'rt.turma_origem_id')
            ->join('turmas as d', 'd.id', '=', 'rt.turma_destino_id')
            ->where('rt.campanha_id', $id)->orderBy('o.nome_turma')
            ->get(['o.id as origem_id', 'o.nome_turma as origem', 'd.id as destino_id', 'd.nome_turma as destino']);
        $respostas = DB::table('rematriculas as r')
            ->leftJoin('parcelas as p', 'p.id', '=', 'r.parcela_cobranca_id')
            ->leftJoin('planos_pagamento as pp', 'pp.id', '=', 'r.plano_id')
            ->where('r.campanha_id', $id)
            ->get(['r.id', 'r.aluno_id', 'r.status', 'r.motivo_recusa', 'r.aceite_em', 'r.confirmada_em', 'r.atualizado_em',
                'r.desconto_antecipado', 'pp.nome as plano', 'p.valor_final as cobranca_valor', 'p.data_vencimento as cobranca_vencimento',
                'p.status as cobranca_status'])
            ->keyBy('aluno_id');

        $turmas = [];
        foreach ($mapa as $m) {
            $alunos = $this->alunosAtivosDaTurma((int) $m->origem_id)->map(function ($a) use ($respostas) {
                $r = $respostas[$a->aluno_id] ?? null;
                $status = $r && $r->status !== 'cancelada' ? $r->status : 'pendente';
                return [
                    'aluno_id' => (int) $a->aluno_id, 'nome' => $a->nome, 'status' => $status,
                    'rematricula_id' => $r ? (int) $r->id : null,
                    'plano' => $r->plano ?? null, 'motivo_recusa' => $r->motivo_recusa ?? null,
                    'aceite_em' => $r->aceite_em ?? null, 'confirmada_em' => $r->confirmada_em ?? null,
                    'desconto_antecipado' => (bool) ($r->desconto_antecipado ?? false),
                    'cobranca' => $r && $r->cobranca_valor !== null ? [
                        'valor' => (float) $r->cobranca_valor, 'vencimento' => $r->cobranca_vencimento, 'status' => $r->cobranca_status,
                    ] : null,
                ];
            })->values()->all();
            $contagem = array_count_values(array_column($alunos, 'status'));
            $turmas[] = [
                'origem_id' => (int) $m->origem_id, 'origem' => $m->origem, 'destino_id' => (int) $m->destino_id, 'destino' => $m->destino,
                'total' => count($alunos), 'contagem' => $contagem, 'alunos' => $alunos,
            ];
        }

        return ['campanha' => $this->formatarCampanha($c), 'resumo' => $this->resumo($id), 'turmas' => $turmas];
    }

    /** Avisa no sino quem ainda não respondeu (ou não pagou). Devolve quantas famílias. */
    public function lembrar(int $id): int
    {
        $c = DB::table('rematricula_campanhas')->where('id', $id)->first();
        if (!$c || $c->status !== 'aberta' || !$this->notificacoes->disponivel()) {
            return 0;
        }
        $respondidos = DB::table('rematriculas')->where('campanha_id', $id)->whereIn('status', ['confirmada', 'recusada'])->pluck('aluno_id')->all();
        $alunos = DB::table('rematricula_turmas as rt')->join('turma_aluno as ta', 'ta.turma_id', '=', 'rt.turma_origem_id')
            ->where('rt.campanha_id', $id)->whereNotIn('ta.aluno_id', $respondidos ?: [0])->pluck('ta.aluno_id')->unique()->all();
        if (!$alunos) {
            return 0;
        }
        $porUsuario = DB::table('aluno_responsavel as ar')->join('responsaveis as r', 'r.id', '=', 'ar.responsavel_id')
            ->join('usuarios as u', 'u.id', '=', 'r.usuario_id')->where('u.ativo', 1)
            ->whereIn('ar.aluno_id', $alunos)->pluck('r.usuario_id')->unique()->all();

        $fim = Carbon::parse($c->fim)->format('d/m');
        $this->notificacoes->gravar($porUsuario, 'rematricula', "rematricula:{$c->id}", null,
            "Rematrícula {$c->ano_destino} aberta", "Garanta a vaga até $fim: escolha o plano, aceite o contrato e pague pelo app.",
            '/rematricula', null);
        return count($porUsuario);
    }

    /** A escola cancela uma rematrícula (ex.: não pagou no prazo); o contrato gerado é cancelado junto. */
    public function cancelar(int $rematriculaId, int $usuarioId): void
    {
        $r = DB::table('rematriculas')->where('id', $rematriculaId)->first();
        if (!$r) {
            throw new \DomainException('Rematrícula não encontrada.');
        }
        if (!in_array($r->status, ['aguardando_pagamento', 'confirmada', 'recusada'], true)) {
            throw new \DomainException('Esta rematrícula já está cancelada.');
        }
        if ($r->matricula_financeira_id) {
            $ativa = DB::table('matriculas_financeiras')->where('id', $r->matricula_financeira_id)->where('status', 'ativa')->exists();
            if ($ativa) {
                app(CancelamentoMatriculaFinanceiraService::class)->cancelar(
                    (int) $r->matricula_financeira_id, 'outro', 'Rematrícula cancelada pela escola', false, $usuarioId);
            }
        }
        DB::table('rematriculas')->where('id', $rematriculaId)->update([
            'status' => 'cancelada', 'cancelada_por' => $usuarioId, 'atualizado_em' => now(),
        ]);
    }

    /** Situação de cada aluno de uma turma, para a tela da virada já vir preenchida. */
    public function situacaoParaVirada(int $turmaOrigemId): array
    {
        if (!$this->disponivel()) {
            return [];
        }
        return DB::table('rematriculas as r')
            ->join('rematricula_campanhas as c', 'c.id', '=', 'r.campanha_id')
            ->where('r.turma_origem_id', $turmaOrigemId)->whereIn('r.status', ['confirmada', 'aguardando_pagamento', 'recusada'])
            ->orderByDesc('c.ano_destino')
            ->get(['r.aluno_id', 'r.status', 'r.turma_destino_id', 'r.motivo_recusa', 'c.ano_destino'])
            ->unique('aluno_id')
            ->map(fn ($r) => [
                'aluno_id' => (int) $r->aluno_id, 'status' => $r->status, 'turma_destino_id' => $r->turma_destino_id ? (int) $r->turma_destino_id : null,
                'motivo_recusa' => $r->motivo_recusa, 'ano_destino' => (int) $r->ano_destino,
            ])->values()->all();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Família
    // ═════════════════════════════════════════════════════════════════════

    /** O que o responsável vê: a campanha aberta e cada dependente que pode rematricular. */
    public function paraResponsavel(int $usuarioId): array
    {
        $c = $this->campanhaAberta();
        if (!$c) {
            return ['campanha' => null, 'dependentes' => []];
        }
        $this->sincronizarPagamentos((int) $c->id);
        $responsavelId = (int) DB::table('responsaveis')->where('usuario_id', $usuarioId)->value('id');

        $deps = DB::table('aluno_responsavel as ar')
            ->join('alunos as a', 'a.id', '=', 'ar.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->join('turma_aluno as ta', 'ta.aluno_id', '=', 'a.id')
            ->join('rematricula_turmas as rt', function ($j) use ($c) {
                $j->on('rt.turma_origem_id', '=', 'ta.turma_id')->where('rt.campanha_id', '=', $c->id);
            })
            ->join('turmas as o', 'o.id', '=', 'rt.turma_origem_id')->join('turmas as d', 'd.id', '=', 'rt.turma_destino_id')
            ->where('ar.responsavel_id', $responsavelId)->where('u.ativo', 1)
            ->orderBy('u.nome_completo')
            ->get(['a.id as aluno_id', 'u.nome_completo as nome', 'a.data_nascimento', 'a.matricula',
                'o.nome_turma as turma_atual', 'd.id as destino_id', 'd.nome_turma as turma_destino']);

        $dependentes = [];
        foreach ($deps->unique('aluno_id') as $dep) {
            $pagador = $this->pagador((int) $dep->aluno_id);
            $r = DB::table('rematriculas')->where('campanha_id', $c->id)->where('aluno_id', $dep->aluno_id)->first();
            $dependentes[] = [
                'aluno_id'       => (int) $dep->aluno_id,
                'nome'           => $dep->nome,
                'matricula'      => $dep->matricula,
                'data_nascimento' => $dep->data_nascimento,
                'turma_atual'    => $dep->turma_atual,
                'turma_destino'  => $dep->turma_destino,
                'pode_assinar'   => $pagador && (int) $pagador['id'] === $responsavelId,
                'pagador_nome'   => $pagador['nome'] ?? null,
                'responsavel'    => $pagador,
                // Sem CPF/endereço válidos o Asaas não emite o boleto: a tela avisa antes do aceite.
                'pendencias_cadastro' => $pagador ? $this->pendenciasCadastro($pagador) : [],
                'planos'         => $this->planosComDesconto($c, (int) $dep->destino_id),
                'ja_tem_contrato' => $this->matriculas->jaMatriculado((int) $dep->aluno_id, (string) $c->ano_destino),
                'rematricula'    => $r && $r->status !== 'cancelada' ? $this->formatarRematricula($r) : null,
            ];
        }

        return ['campanha' => $this->formatarCampanha($c) + ['desconto_vigente' => $this->descontoVigente($c)], 'dependentes' => $dependentes];
    }

    /** Contrato preenchido para a família ler antes de aceitar (com o hash que ela devolve no aceite). */
    public function contratoParaAceite(int $usuarioId, int $alunoId, int $planoId): array
    {
        [$c, $ctx] = $this->contexto($usuarioId, $alunoId, $planoId);
        $html = $this->contrato->montar($c->contrato_modelo, $this->contrato->campos($c, $ctx));
        return ['html' => $html, 'hash' => $this->contrato->hash($html), 'valores' => [
            'valor_parcela' => $ctx['valor_parcela'], 'valor_total' => $ctx['valor_total'], 'desconto' => $ctx['desconto'],
            'parcelas' => (int) $ctx['plano']->numero_parcelas,
        ]];
    }

    /**
     * Aceite do contrato: gera o contrato do ano novo e a cobrança do momento.
     * $hashVisto é o hash do texto que a família leu — se o contrato mudou no
     * meio do caminho (escola editou, virou o dia), o aceite é recusado.
     *
     * @throws \DomainException
     */
    public function aceitar(int $usuarioId, int $alunoId, int $planoId, string $hashVisto, ?string $ip, ?string $userAgent): array
    {
        [$c, $ctx] = $this->contexto($usuarioId, $alunoId, $planoId);
        $html = $this->contrato->montar($c->contrato_modelo, $this->contrato->campos($c, $ctx));
        $hash = $this->contrato->hash($html);
        if (!hash_equals($hash, $hashVisto)) {
            throw new \DomainException('O contrato foi atualizado desde que você abriu. Leia a versão nova e aceite de novo.');
        }
        if ($this->matriculas->jaMatriculado($alunoId, (string) $c->ano_destino)) {
            throw new \DomainException("Este aluno já tem contrato para {$c->ano_destino}. Fale com a secretaria.");
        }

        $hoje = Carbon::today();
        $vencimento = $hoje->copy()->addDays((int) $c->prazo_pagamento_dias)->toDateString();
        $antecipado = $this->descontoVigente($c) !== null;

        $resultado = DB::transaction(function () use ($c, $ctx, $alunoId, $planoId, $usuarioId, $html, $hash, $ip, $userAgent, $vencimento, $antecipado) {
            // Trava a linha do aluno nesta campanha: dois cliques ao mesmo tempo não geram dois contratos.
            $existente = DB::table('rematriculas')->where('campanha_id', $c->id)->where('aluno_id', $alunoId)->lockForUpdate()->first();
            if ($existente && in_array($existente->status, ['aguardando_pagamento', 'confirmada'], true)) {
                throw new \DomainException('A rematrícula deste aluno já foi feita.');
            }

            $mf = $this->matriculas->criar($usuarioId, [
                'aluno_id' => $alunoId, 'plano_id' => $planoId, 'ano_letivo' => (string) $c->ano_destino,
                'data_inicio' => $this->contratos->dataInicioSugerida((int) $c->ano_destino),
                'desconto_extra_tipo' => $antecipado ? $c->desconto_tipo : null,
                'desconto_extra_valor' => $antecipado ? (float) $c->desconto_valor : null,
                'desconto_extra_descricao' => $antecipado ? 'Rematrícula antecipada' : '',
                'observacoes' => "Rematrícula online {$c->ano_destino} — contrato aceito pelo responsável no app.",
            ]);

            $parcelaCobranca = null;
            if ($c->cobranca === 'primeira_parcela') {
                $parcelaCobranca = (int) DB::table('parcelas')->where('matricula_financeira_id', $mf['matricula_id'])
                    ->where('numero_parcela', 1)->value('id');
                DB::table('parcelas')->where('id', $parcelaCobranca)->update(['data_vencimento' => $vencimento]);
            } elseif ($c->cobranca === 'taxa') {
                // Taxa de matrícula = parcela nº 0 do contrato (sem desconto).
                $parcelaCobranca = (int) DB::table('parcelas')->insertGetId([
                    'matricula_financeira_id' => $mf['matricula_id'], 'aluno_id' => $alunoId, 'numero_parcela' => 0,
                    'valor_original' => (float) $c->taxa_valor, 'desconto_aplicado' => 0, 'valor_final' => (float) $c->taxa_valor,
                    'data_vencimento' => $vencimento, 'observacoes' => "Taxa de matrícula {$c->ano_destino}",
                ]);
            }

            $valorCobranca = $parcelaCobranca ? (float) DB::table('parcelas')->where('id', $parcelaCobranca)->value('valor_final') : 0.0;
            $confirmada = !$parcelaCobranca || $valorCobranca <= 0;

            $dados = [
                'responsavel_id' => $ctx['responsavel']['id'], 'usuario_id' => $usuarioId,
                'turma_origem_id' => $ctx['turma_origem_id'], 'turma_destino_id' => $ctx['turma_destino_id'],
                'plano_id' => $planoId, 'status' => $confirmada ? 'confirmada' : 'aguardando_pagamento', 'motivo_recusa' => null,
                'matricula_financeira_id' => $mf['matricula_id'], 'parcela_cobranca_id' => $parcelaCobranca,
                'desconto_antecipado' => $antecipado ? 1 : 0, 'contrato_html' => $html, 'contrato_hash' => $hash,
                'aceite_em' => now(), 'aceite_ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'aceite_user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'confirmada_em' => $confirmada ? now() : null, 'cancelada_por' => null, 'atualizado_em' => now(),
            ];
            if ($existente) {
                DB::table('rematriculas')->where('id', $existente->id)->update($dados);
                $id = (int) $existente->id;
            } else {
                $id = DB::table('rematriculas')->insertGetId($dados + ['campanha_id' => $c->id, 'aluno_id' => $alunoId]);
            }
            return ['id' => $id, 'parcela_id' => $parcelaCobranca, 'confirmada' => $confirmada];
        });

        // Boleto/Pix no Asaas fora da transação: se o Asaas falhar, o contrato
        // fica feito e a família paga pela emissão automática ou na secretaria.
        $boleto = false;
        if (!$resultado['confirmada'] && $resultado['parcela_id'] && $this->financeiro->modoAsaas()) {
            try {
                app(AsaasCobrancaService::class)->emitir($resultado['parcela_id'], null, false, $usuarioId);
                $boleto = true;
            } catch (\Throwable $e) {
                Log::warning('Rematrícula: falha ao emitir no Asaas', ['parcela' => $resultado['parcela_id'], 'erro' => $e->getMessage()]);
            }
        }

        $r = DB::table('rematriculas')->where('id', $resultado['id'])->first();
        return $this->formatarRematricula($r) + ['boleto_emitido' => $boleto];
    }

    /** "Não vamos renovar" (com motivo). Pode ser desfeito enquanto a campanha estiver aberta. */
    public function recusar(int $usuarioId, int $alunoId, ?string $motivo): void
    {
        $c = $this->campanhaAberta();
        if (!$c) {
            throw new \DomainException('A rematrícula não está aberta.');
        }
        $ctx = $this->vinculo($c, $usuarioId, $alunoId);
        $existente = DB::table('rematriculas')->where('campanha_id', $c->id)->where('aluno_id', $alunoId)->first();
        if ($existente && in_array($existente->status, ['aguardando_pagamento', 'confirmada'], true)) {
            throw new \DomainException('A rematrícula já foi feita. Para desistir, fale com a secretaria.');
        }
        $dados = [
            'responsavel_id' => $ctx['responsavel']['id'], 'usuario_id' => $usuarioId,
            'turma_origem_id' => $ctx['turma_origem_id'], 'turma_destino_id' => $ctx['turma_destino_id'],
            'status' => 'recusada', 'motivo_recusa' => $motivo ? mb_substr(trim($motivo), 0, 255) : null, 'atualizado_em' => now(),
        ];
        if ($existente) {
            DB::table('rematriculas')->where('id', $existente->id)->update($dados);
        } else {
            DB::table('rematriculas')->insert($dados + ['campanha_id' => $c->id, 'aluno_id' => $alunoId]);
        }
    }

    /** Volta atrás no "não vamos renovar". */
    public function desfazerRecusa(int $usuarioId, int $alunoId): void
    {
        $c = $this->campanhaAberta();
        if (!$c) {
            throw new \DomainException('A rematrícula não está aberta.');
        }
        $this->vinculo($c, $usuarioId, $alunoId);
        DB::table('rematriculas')->where('campanha_id', $c->id)->where('aluno_id', $alunoId)->where('status', 'recusada')->delete();
    }

    /** Cópia do contrato aceito (família e escola). */
    public function contratoAceito(int $rematriculaId): ?array
    {
        $r = DB::table('rematriculas as r')->join('alunos as a', 'a.id', '=', 'r.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->leftJoin('usuarios as q', 'q.id', '=', 'r.usuario_id')
            ->where('r.id', $rematriculaId)->whereNotNull('r.contrato_html')
            ->first(['r.id', 'r.aluno_id', 'r.contrato_html', 'r.contrato_hash', 'r.aceite_em', 'r.aceite_ip', 'r.aceite_user_agent',
                'u.nome_completo as aluno', 'q.nome_completo as aceito_por']);
        return $r ? (array) $r : null;
    }

    public function podeVerContrato(int $usuarioId, int $rematriculaId): bool
    {
        return DB::table('rematriculas as r')->join('aluno_responsavel as ar', 'ar.aluno_id', '=', 'r.aluno_id')
            ->join('responsaveis as rs', 'rs.id', '=', 'ar.responsavel_id')
            ->where('r.id', $rematriculaId)->where('rs.usuario_id', $usuarioId)->exists();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Pagamento
    // ═════════════════════════════════════════════════════════════════════

    /** Chamado na baixa de qualquer parcela: se era a da rematrícula, confirma. */
    public function parcelaPaga(int $parcelaId): void
    {
        if (!$this->disponivel()) {
            return;
        }
        DB::table('rematriculas')->where('parcela_cobranca_id', $parcelaId)->where('status', 'aguardando_pagamento')
            ->update(['status' => 'confirmada', 'confirmada_em' => now(), 'atualizado_em' => now()]);
    }

    /** Rede de segurança: confirma as que já foram pagas (ex.: baixa feita antes desta versão). */
    public function sincronizarPagamentos(int $campanhaId): void
    {
        DB::table('rematriculas as r')->join('parcelas as p', 'p.id', '=', 'r.parcela_cobranca_id')
            ->where('r.campanha_id', $campanhaId)->where('r.status', 'aguardando_pagamento')->where('p.status', 'pago')
            ->update(['r.status' => 'confirmada', 'r.confirmada_em' => now(), 'r.atualizado_em' => now()]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Apoio
    // ═════════════════════════════════════════════════════════════════════

    private function campanhaAberta(): ?object
    {
        if (!$this->disponivel()) {
            return null;
        }
        $hoje = now()->toDateString();
        return DB::table('rematricula_campanhas')->where('status', 'aberta')
            ->where('inicio', '<=', $hoje)->where('fim', '>=', $hoje)->orderByDesc('ano_destino')->first();
    }

    /**
     * Confere se o usuário é responsável pelo aluno e o aluno está numa turma da campanha.
     * @throws \DomainException
     */
    private function vinculo(object $c, int $usuarioId, int $alunoId): array
    {
        $responsavel = DB::table('responsaveis as r')->join('aluno_responsavel as ar', 'ar.responsavel_id', '=', 'r.id')
            ->where('r.usuario_id', $usuarioId)->where('ar.aluno_id', $alunoId)->value('r.id');
        if (!$responsavel) {
            throw new \DomainException('Aluno não encontrado.');
        }
        $turma = DB::table('turma_aluno as ta')->join('rematricula_turmas as rt', 'rt.turma_origem_id', '=', 'ta.turma_id')
            ->where('rt.campanha_id', $c->id)->where('ta.aluno_id', $alunoId)->first(['rt.turma_origem_id', 'rt.turma_destino_id']);
        if (!$turma) {
            throw new \DomainException('A turma deste aluno não está na rematrícula. Fale com a secretaria.');
        }
        $pagador = $this->pagador($alunoId);
        return [
            'responsavel' => $pagador ?? ['id' => (int) $responsavel],
            'turma_origem_id' => (int) $turma->turma_origem_id, 'turma_destino_id' => (int) $turma->turma_destino_id,
            'eh_pagador' => $pagador && (int) $pagador['id'] === (int) $responsavel,
        ];
    }

    /**
     * Tudo que o contrato precisa. Só o responsável financeiro pode aceitar.
     * @return array{0: object, 1: array}
     * @throws \DomainException
     */
    private function contexto(int $usuarioId, int $alunoId, int $planoId): array
    {
        $c = $this->campanhaAberta();
        if (!$c) {
            throw new \DomainException('A rematrícula não está aberta.');
        }
        $v = $this->vinculo($c, $usuarioId, $alunoId);
        if (!$v['eh_pagador']) {
            throw new \DomainException('Só o responsável financeiro do aluno pode aceitar o contrato.');
        }
        $plano = collect($this->planosComDesconto($c, $v['turma_destino_id']))->firstWhere('id', $planoId);
        if (!$plano) {
            throw new \DomainException('Escolha um dos planos da turma.');
        }
        $aluno = DB::table('alunos as a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')->where('a.id', $alunoId)
            ->first(['u.nome_completo as nome', 'a.data_nascimento']);
        $turma = DB::table('turmas')->where('id', $v['turma_destino_id'])->value('nome_turma');
        $planoDb = DB::table('planos_pagamento')->where('id', $planoId)->first(['nome', 'numero_parcelas', 'dia_vencimento']);

        return [$c, [
            'responsavel' => $v['responsavel'], 'aluno' => $aluno, 'turma' => $turma, 'plano' => $planoDb,
            'valor_parcela' => $plano['valor_parcela'], 'valor_total' => $plano['valor_total'],
            'desconto' => $plano['desconto_texto'] ?? 'sem desconto', 'data' => now(),
            'turma_origem_id' => $v['turma_origem_id'], 'turma_destino_id' => $v['turma_destino_id'],
        ]];
    }

    /** Planos da turma do ano novo com o desconto antecipado já aplicado (mesma conta do contrato). */
    private function planosComDesconto(object $c, int $turmaDestinoId): array
    {
        $planos = $this->contratos->planosPorTurma([$turmaDestinoId])[$turmaDestinoId] ?? [];
        $desc = $this->descontoVigente($c);
        $diaVenc = DB::table('planos_pagamento')->whereIn('id', array_column($planos, 'id') ?: [0])->pluck('dia_vencimento', 'id');

        return array_map(function ($p) use ($desc, $diaVenc) {
            $parcela = $p['valor_parcela'];
            $comDesconto = $parcela;
            $texto = null;
            if ($desc) {
                $abate = $desc['tipo'] === 'percentual' ? $parcela * $desc['valor'] / 100 : $desc['valor'] / max(1, $p['numero_parcelas']);
                $comDesconto = round(max(0, $parcela - round($abate, 2)), 2);
                $texto = $desc['tipo'] === 'percentual'
                    ? rtrim(rtrim(number_format($desc['valor'], 2, ',', '.'), '0'), ',') . '% de rematrícula antecipada'
                    : 'R$ ' . number_format($desc['valor'], 2, ',', '.') . ' de rematrícula antecipada';
            }
            // array_merge (e não +): os valores com desconto substituem os do plano.
            return array_merge($p, [
                'dia_vencimento' => (int) ($diaVenc[$p['id']] ?? 10),
                'valor_parcela_original' => $parcela,
                'valor_parcela' => $comDesconto,
                'valor_total' => round($comDesconto * $p['numero_parcelas'], 2),
                'desconto_texto' => $texto,
            ]);
        }, $planos);
    }

    /** Desconto antecipado valendo hoje (ou null). */
    private function descontoVigente(object $c): ?array
    {
        if (!$c->desconto_tipo || !$c->desconto_ate || now()->toDateString() > $c->desconto_ate) {
            return null;
        }
        return ['tipo' => $c->desconto_tipo, 'valor' => (float) $c->desconto_valor, 'ate' => $c->desconto_ate];
    }

    private function pagador(int $alunoId): ?array
    {
        $r = DB::table('responsaveis as r')->join('aluno_responsavel as ar', 'r.id', '=', 'ar.responsavel_id')
            ->join('usuarios as u', 'r.usuario_id', '=', 'u.id')->where('ar.aluno_id', $alunoId)
            ->orderByDesc('ar.principal')->orderBy('r.id')
            ->first(['r.id', 'u.nome_completo as nome', 'u.email', 'r.cpf', 'r.telefone', 'r.endereco', 'r.numero', 'r.complemento',
                'r.bairro', 'r.cidade', 'r.uf', 'r.cep']);
        return $r ? ['id' => (int) $r->id] + (array) $r : null;
    }

    /** Mesmas regras da lista de responsáveis (CPF, telefone e endereço completos). */
    private function pendenciasCadastro(array $r): array
    {
        $v = app(\App\Services\Cadastro\CadastroValidacaoService::class);
        return array_values(array_filter([
            !$v->cpfValido($r['cpf'] ?? null) ? 'CPF' : null,
            !$v->telefoneValido($r['telefone'] ?? null) ? 'telefone' : null,
            $v->validarEndereco($r, true) ? 'endereço' : null,
        ]));
    }

    private function alunosAtivosDaTurma(int $turmaId)
    {
        return DB::table('turma_aluno as ta')->join('alunos as a', 'a.id', '=', 'ta.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('ta.turma_id', $turmaId)->where('u.ativo', 1)->orderBy('u.nome_completo')
            ->get(['a.id as aluno_id', 'u.nome_completo as nome']);
    }

    private function resumo(int $campanhaId): array
    {
        $total = DB::table('rematricula_turmas as rt')->join('turma_aluno as ta', 'ta.turma_id', '=', 'rt.turma_origem_id')
            ->join('alunos as a', 'a.id', '=', 'ta.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('rt.campanha_id', $campanhaId)->where('u.ativo', 1)->distinct()->count('ta.aluno_id');
        $por = DB::table('rematriculas')->where('campanha_id', $campanhaId)->where('status', '!=', 'cancelada')
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $confirmadas = (int) ($por['confirmada'] ?? 0);
        $aguardando = (int) ($por['aguardando_pagamento'] ?? 0);
        $recusadas = (int) ($por['recusada'] ?? 0);
        return [
            'total' => $total, 'confirmadas' => $confirmadas, 'aguardando_pagamento' => $aguardando, 'recusadas' => $recusadas,
            'pendentes' => max(0, $total - $confirmadas - $aguardando - $recusadas),
            'percentual' => $total ? round($confirmadas * 100 / $total) : 0,
        ];
    }

    private function formatarCampanha(object $c): array
    {
        $hoje = now()->toDateString();
        return [
            'id' => (int) $c->id, 'ano_destino' => (int) $c->ano_destino, 'inicio' => $c->inicio, 'fim' => $c->fim,
            'cobranca' => $c->cobranca, 'taxa_valor' => $c->taxa_valor !== null ? (float) $c->taxa_valor : null,
            'prazo_pagamento_dias' => (int) $c->prazo_pagamento_dias,
            'desconto_tipo' => $c->desconto_tipo, 'desconto_valor' => $c->desconto_valor !== null ? (float) $c->desconto_valor : null,
            'desconto_ate' => $c->desconto_ate, 'status' => $c->status,
            'no_periodo' => $c->inicio <= $hoje && $c->fim >= $hoje,
        ];
    }

    private function formatarRematricula(object $r): array
    {
        $cobranca = null;
        if ($r->parcela_cobranca_id) {
            $p = DB::table('parcelas')->where('id', $r->parcela_cobranca_id)
                ->first(['id', 'numero_parcela', 'valor_final', 'data_vencimento', 'status']);
            $asaas = Schema::hasTable('asaas_cobrancas')
                ? DB::table('asaas_cobrancas')->where('parcela_id', $r->parcela_cobranca_id)->where('ativa', 1)->first(['invoice_url', 'bank_slip_url', 'linha_digitavel'])
                : null;
            if ($p) {
                $cobranca = [
                    'parcela_id' => (int) $p->id, 'taxa' => (int) $p->numero_parcela === 0, 'valor' => (float) $p->valor_final,
                    'vencimento' => $p->data_vencimento, 'status' => $p->status,
                    'invoice_url' => $asaas->invoice_url ?? null, 'boleto_url' => $asaas->bank_slip_url ?? null,
                    'linha_digitavel' => $asaas->linha_digitavel ?? null,
                ];
            }
        }
        return [
            'id' => (int) $r->id, 'status' => $r->status, 'motivo_recusa' => $r->motivo_recusa,
            'plano_id' => $r->plano_id ? (int) $r->plano_id : null, 'aceite_em' => $r->aceite_em, 'confirmada_em' => $r->confirmada_em,
            'desconto_antecipado' => (bool) $r->desconto_antecipado, 'tem_contrato' => !empty($r->contrato_html), 'cobranca' => $cobranca,
        ];
    }

    /** "1º Ano" → "1o ano"; tira ano solto ("3º Ano - 2027"), acentos e espaços extras. */
    private function serie(string $nome): string
    {
        $n = mb_strtolower(trim($nome));
        $n = strtr($n, ['º' => 'o', '°' => 'o', 'ª' => 'a', 'ç' => 'c', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ã' => 'a', 'õ' => 'o', 'â' => 'a', 'ê' => 'e']);
        $n = preg_replace('/\s*[-–]?\s*\b(19|20)\d{2}\b\s*/u', ' ', $n);
        $n = preg_replace('/(\d+)\s*o\b/', '$1o', $n);
        return trim(preg_replace('/\s+/', ' ', $n));
    }

    /** Próximas séries possíveis, da mais provável para a menos (o nome muda de escola para escola). */
    private function seriesSeguintes(string $nome): array
    {
        $s = $this->serie($nome);
        // "1o ano", "2o periodo b": sobe o número e mantém o sufixo (turma A/B), se houver.
        if (preg_match('/^(\d+)o (ano|periodo)(.*)$/', $s, $m)) {
            if ($m[2] === 'periodo' && (int) $m[1] >= 3) {
                return array_values(array_unique(['1o ano' . $m[3], '1o ano']));
            }
            $seguinte = ((int) $m[1] + 1) . "o {$m[2]}";
            return array_values(array_unique([$seguinte . $m[3], $seguinte]));
        }
        return match ($s) {
            'bercario i' => ['bercario ii'],
            'bercario ii' => ['maternal i', 'maternal'],
            'maternal i' => ['maternal ii', '1o periodo'],
            'maternal ii', 'maternal' => ['1o periodo'],
            default => [],
        };
    }
}
