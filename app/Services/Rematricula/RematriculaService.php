<?php

namespace App\Services\Rematricula;

use App\Services\Financeiro\Asaas\AsaasCobrancaService;
use App\Services\Financeiro\CancelamentoMatriculaFinanceiraService;
use App\Services\Financeiro\ContratoFinanceiroService;
use App\Services\Financeiro\FinanceiroConfigService;
use App\Services\Financeiro\FinanceiroHelperService;
use App\Services\Financeiro\MatriculaFinanceiraService;
use App\Services\Matriculas\NumeroMatriculaService;
use App\Services\Notificacoes\NotificacaoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Rematrícula: garante a vaga do aluno no ano seguinte.
 *
 * A escola abre uma campanha para o ano novo, diz para qual turma vai cada
 * turma atual (sugestão — cada aluno pode ir para outra) e o que cobra na hora
 * (1ª parcela, taxa ou nada). A rematrícula é feita de dois jeitos, que dão
 * no mesmo resultado:
 * - pela família no app: confere os dados, escolhe o plano, aceita o contrato
 *   e paga (ou responde que não vai renovar);
 * - pela secretaria, com a família presente: escolhe turma, plano e desconto,
 *   imprime o contrato para assinar e recebe na hora ou gera o boleto.
 *
 * Em qualquer caso, na hora: contrato do ano novo (matrícula financeira +
 * parcelas), cobrança do momento e número de matrícula do ano novo reservado
 * (ex.: 2027001). A rematrícula vira "confirmada" quando a cobrança é paga —
 * ou já no aceite, se não há cobrança.
 *
 * O aluno NÃO muda de turma aqui: continua na turma atual até o encerramento
 * do ano da turma (ViradaAnoService), que efetiva a reserva.
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
        private NumeroMatriculaService $numeros,
    ) {
    }

    public function disponivel(): bool
    {
        // Escola que ainda não rodou as migrations (2026_10_24_100000 e 2026_10_27_100000): o resto do sistema segue normal.
        return $this->tabelas ??= Schema::hasTable('rematriculas') && Schema::hasColumn('rematriculas', 'efetivada_em');
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

    /**
     * Painel: por turma de origem, cada aluno e a situação da rematrícula.
     * Mostra todas as turmas do ano anterior — as sem turma padrão no ano novo
     * (ex.: última série) também, porque a secretaria pode rematricular
     * qualquer aluno individualmente. Quem já mudou de turma na virada
     * continua aparecendo pela rematrícula.
     */
    public function painel(int $id): ?array
    {
        $c = DB::table('rematricula_campanhas')->where('id', $id)->first();
        if (!$c) {
            return null;
        }
        $this->sincronizarPagamentos($id);

        $mapa = DB::table('rematricula_turmas as rt')->join('turmas as d', 'd.id', '=', 'rt.turma_destino_id')
            ->where('rt.campanha_id', $id)->get(['rt.turma_origem_id', 'd.id as destino_id', 'd.nome_turma as destino'])
            ->keyBy('turma_origem_id');
        $respostas = DB::table('rematriculas as r')
            ->leftJoin('parcelas as p', 'p.id', '=', 'r.parcela_cobranca_id')
            ->leftJoin('planos_pagamento as pp', 'pp.id', '=', 'r.plano_id')
            ->leftJoin('turmas as td', 'td.id', '=', 'r.turma_destino_id')
            ->leftJoin('alunos as a', 'a.id', '=', 'r.aluno_id')->leftJoin('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('r.campanha_id', $id)
            ->get(['r.id', 'r.aluno_id', 'r.status', 'r.motivo_recusa', 'r.aceite_em', 'r.confirmada_em', 'r.atualizado_em',
                'r.desconto_antecipado', 'r.turma_origem_id', 'r.turma_destino_id', 'td.nome_turma as turma_destino',
                'r.numero_matricula', 'r.origem', 'r.efetivada_em', 'u.nome_completo as nome',
                'pp.nome as plano', 'p.valor_final as cobranca_valor', 'p.data_vencimento as cobranca_vencimento',
                'p.status as cobranca_status'])
            ->keyBy('aluno_id');

        // Quem saiu no encerramento do ano (não renovou / formando) continua listado: pode voltar (rematrícula tardia).
        $saidas = $this->numeros->porAno() ? DB::table('matriculas as m')->join('alunos as a', 'a.id', '=', 'm.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('m.ano_letivo', (int) $c->ano_destino - 1)->whereIn('m.resultado', ['nao_renovou', 'concluiu'])
            ->get(['m.aluno_id', 'm.turma_id', 'm.resultado', 'u.nome_completo as nome']) : collect();

        $idsOrigem = DB::table('turmas')->where('ano_letivo', (int) $c->ano_destino - 1)->where('status', 'Ativa')->pluck('id')
            ->merge($respostas->pluck('turma_origem_id')->filter())->merge($saidas->pluck('turma_id'))
            ->map(fn ($v) => (int) $v)->unique()->all();
        $origens = DB::table('turmas')->whereIn('id', $idsOrigem ?: [0])->orderBy('nome_turma')->get(['id', 'nome_turma', 'status']);

        $turmas = [];
        foreach ($origens as $o) {
            $m = $mapa[$o->id] ?? null;
            $naTurma = $this->alunosAtivosDaTurma((int) $o->id)->keyBy('aluno_id');
            // Já efetivados na virada (saíram da turma) seguem na lista pela rematrícula.
            foreach ($respostas->where('turma_origem_id', $o->id) as $r) {
                if (!$naTurma->has($r->aluno_id) && $r->status !== 'cancelada' && $r->status !== 'recusada') {
                    $naTurma[$r->aluno_id] = (object) ['aluno_id' => $r->aluno_id, 'nome' => $r->nome];
                }
            }
            foreach ($saidas->where('turma_id', $o->id) as $s) {
                if (!$naTurma->has($s->aluno_id)) {
                    $naTurma[$s->aluno_id] = (object) ['aluno_id' => $s->aluno_id, 'nome' => $s->nome, 'saiu' => $s->resultado];
                }
            }
            if ($naTurma->isEmpty()) {
                continue;
            }
            $alunos = $naTurma->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->map(function ($a) use ($respostas) {
                $r = $respostas[$a->aluno_id] ?? null;
                $ativa = $r && $r->status !== 'cancelada';
                return [
                    // Saiu no encerramento do ano sem rematrícula: 'nao_renovou' | 'concluiu'.
                    'saiu' => !($ativa && in_array($r->status, ['confirmada', 'aguardando_pagamento'], true)) ? ($a->saiu ?? null) : null,
                    'aluno_id' => (int) $a->aluno_id, 'nome' => $a->nome, 'status' => $ativa ? $r->status : 'pendente',
                    'rematricula_id' => $r ? (int) $r->id : null,
                    'plano' => $ativa ? $r->plano : null, 'motivo_recusa' => $r->motivo_recusa ?? null,
                    'aceite_em' => $r->aceite_em ?? null, 'confirmada_em' => $r->confirmada_em ?? null,
                    'desconto_antecipado' => (bool) ($r->desconto_antecipado ?? false),
                    'numero_matricula' => $ativa ? $r->numero_matricula : null,
                    'turma_destino_id' => $ativa && $r->turma_destino_id ? (int) $r->turma_destino_id : null,
                    'turma_destino' => $ativa ? $r->turma_destino : null,
                    'origem' => $r->origem ?? null,
                    'efetivada' => $ativa && !empty($r->efetivada_em),
                    'cobranca' => $ativa && $r->cobranca_valor !== null ? [
                        'valor' => (float) $r->cobranca_valor, 'vencimento' => $r->cobranca_vencimento, 'status' => $r->cobranca_status,
                    ] : null,
                ];
            })->values()->all();
            $contagem = array_count_values(array_column($alunos, 'status'));
            $turmas[] = [
                'origem_id' => (int) $o->id, 'origem' => $o->nome_turma, 'origem_encerrada' => $o->status !== 'Ativa',
                'destino_id' => $m ? (int) $m->destino_id : null, 'destino' => $m->destino ?? null,
                'total' => count($alunos), 'contagem' => $contagem, 'alunos' => $alunos,
            ];
        }
        // Turmas com destino padrão primeiro; as sem destino (ex.: formandos) no fim.
        usort($turmas, fn ($a, $b) => [$a['destino_id'] === null, $a['origem']] <=> [$b['destino_id'] === null, $b['origem']]);

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
        if (!empty($r->efetivada_em)) {
            throw new \DomainException('O aluno já está na turma do ano novo. Para tirá-lo, use o cancelamento de matrícula no cadastro do aluno.');
        }
        if ($r->matricula_financeira_id) {
            $ativa = DB::table('matriculas_financeiras')->where('id', $r->matricula_financeira_id)->where('status', 'ativa')->exists();
            if ($ativa) {
                app(CancelamentoMatriculaFinanceiraService::class)->cancelar(
                    (int) $r->matricula_financeira_id, 'outro', 'Rematrícula cancelada pela escola', false, $usuarioId);
            }
        }
        // Rematrícula sem cobrança: tira a marcação "sem cobrança" do ano novo.
        if (!$r->matricula_financeira_id && !$r->plano_id && in_array($r->status, ['confirmada', 'aguardando_pagamento'], true)) {
            $ano = (int) DB::table('rematricula_campanhas')->where('id', $r->campanha_id)->value('ano_destino');
            $this->contratos->removerDispensa((int) $r->aluno_id, $ano);
        }
        DB::table('rematriculas')->where('id', $rematriculaId)->update([
            'status' => 'cancelada', 'cancelada_por' => $usuarioId, 'atualizado_em' => now(),
        ]);
    }

    /**
     * Situação de cada aluno de uma turma, para o encerramento do ano já vir
     * preenchido: quem rematriculou (turma reservada, número, contrato) e quem
     * disse que não vai renovar. Busca pelo aluno (e não pela turma da
     * campanha), porque a reserva é individual.
     */
    public function situacaoParaVirada(int $turmaOrigemId): array
    {
        if (!$this->disponivel()) {
            return [];
        }
        $ano = (int) DB::table('turmas')->where('id', $turmaOrigemId)->value('ano_letivo');
        $alunos = DB::table('turma_aluno')->where('turma_id', $turmaOrigemId)->pluck('aluno_id')->all();
        return DB::table('rematriculas as r')
            ->join('rematricula_campanhas as c', 'c.id', '=', 'r.campanha_id')
            ->leftJoin('turmas as td', 'td.id', '=', 'r.turma_destino_id')
            ->whereIn('r.aluno_id', $alunos ?: [0])->where('c.ano_destino', $ano + 1)
            ->whereIn('r.status', ['confirmada', 'aguardando_pagamento', 'recusada'])->whereNull('r.efetivada_em')
            ->leftJoin('planos_pagamento as pp', 'pp.id', '=', 'r.plano_id')
            ->get(['r.id', 'r.aluno_id', 'r.status', 'r.turma_destino_id', 'td.nome_turma as turma_destino', 'r.motivo_recusa',
                'r.numero_matricula', 'r.origem', 'c.ano_destino', 'r.plano_id', 'pp.nome as plano'])
            ->map(fn ($r) => [
                'rematricula_id' => (int) $r->id, 'aluno_id' => (int) $r->aluno_id, 'status' => $r->status,
                'plano_id' => $r->plano_id ? (int) $r->plano_id : null, 'plano' => $r->plano,
                'turma_destino_id' => $r->turma_destino_id ? (int) $r->turma_destino_id : null, 'turma_destino' => $r->turma_destino,
                'motivo_recusa' => $r->motivo_recusa, 'numero_matricula' => $r->numero_matricula, 'origem' => $r->origem,
                'ano_destino' => (int) $r->ano_destino,
            ])->values()->all();
    }

    /** Rematrícula feita (vaga garantida) e ainda não efetivada do aluno para o ano seguinte ao $anoOrigem. */
    public function reservaDoAluno(int $alunoId, int $anoOrigem): ?object
    {
        if (!$this->disponivel()) {
            return null;
        }
        return DB::table('rematriculas as r')->join('rematricula_campanhas as c', 'c.id', '=', 'r.campanha_id')
            ->where('r.aluno_id', $alunoId)->where('c.ano_destino', $anoOrigem + 1)
            ->whereIn('r.status', ['aguardando_pagamento', 'confirmada'])->whereNull('r.efetivada_em')
            ->lockForUpdate()->first(['r.id', 'r.turma_destino_id', 'r.numero_matricula', 'r.matricula_financeira_id', 'c.ano_destino']);
    }

    /** O aluno entrou na turma do ano novo (encerramento da turma de origem). */
    public function efetivar(int $rematriculaId, int $turmaDestinoId, int $matriculaId): void
    {
        DB::table('rematriculas')->where('id', $rematriculaId)->update([
            'turma_destino_id' => $turmaDestinoId, 'matricula_id' => $matriculaId, 'efetivada_em' => now(), 'atualizado_em' => now(),
        ]);
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

            $parcelaCobranca = $this->gerarCobranca($c, (int) $mf['matricula_id'], $alunoId, $vencimento);
            $confirmada = $this->cobrancaQuitada($parcelaCobranca);

            $dados = [
                'responsavel_id' => $ctx['responsavel']['id'], 'usuario_id' => $usuarioId, 'origem' => 'familia', 'atendido_por' => null,
                'turma_origem_id' => $ctx['turma_origem_id'], 'turma_destino_id' => $ctx['turma_destino_id'],
                'numero_matricula' => $existente->numero_matricula ?? $this->numeros->proximo((int) $c->ano_destino),
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

        $boleto = !$resultado['confirmada'] && $this->emitirBoleto($resultado['parcela_id'], $usuarioId);

        $r = DB::table('rematriculas')->where('id', $resultado['id'])->first();
        return $this->formatarRematricula($r) + ['boleto_emitido' => $boleto];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Secretaria: rematrícula presencial (individual)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Tudo que o atendimento presencial precisa para um aluno: turma atual,
     * turma sugerida e as do ano novo (com os planos de cada uma), desconto
     * antecipado vigente, o que a campanha cobra na hora, o responsável
     * financeiro e se ele já tem contrato do ano novo.
     *
     * @throws \DomainException
     */
    public function opcoesPresencial(int $campanhaId, int $alunoId): array
    {
        $c = $this->campanhaParaAtendimento($campanhaId);
        $aluno = $this->alunoParaAtendimento($c, $alunoId);
        $ano = (int) $c->ano_destino;

        $destinos = DB::table('turmas')->where('ano_letivo', $ano)->where('status', 'Ativa')->orderBy('nome_turma')
            ->get(['id', 'nome_turma', 'turno', 'capacidade']);
        $planos = $this->contratos->planosPorTurma($destinos->pluck('id')->map(fn ($v) => (int) $v)->all());
        $ocupacao = $this->ocupacaoAnoNovo($ano);

        $pagador = $this->pagador($alunoId);
        $contrato = DB::table('matriculas_financeiras as mf')->join('planos_pagamento as p', 'p.id', '=', 'mf.plano_id')
            ->where('mf.aluno_id', $alunoId)->where('mf.ano_letivo', (string) $ano)->where('mf.status', 'ativa')
            ->first(['mf.id', 'p.nome as plano']);
        $r = DB::table('rematriculas')->where('campanha_id', $c->id)->where('aluno_id', $alunoId)->first();

        return [
            'campanha' => $this->formatarCampanha($c) + ['desconto_vigente' => $this->descontoVigente($c)],
            'aluno' => $aluno,
            'turma_destino_sugerida' => $this->destinoSugerido($c, $aluno['turma_id'], $aluno['turma']),
            'turmas_destino' => $destinos->map(fn ($t) => [
                'id' => (int) $t->id, 'nome' => $t->nome_turma, 'turno' => $t->turno,
                'capacidade' => $t->capacidade !== null ? (int) $t->capacidade : null,
                'ocupacao' => $ocupacao[(int) $t->id] ?? 0,
                'planos' => array_column($planos[(int) $t->id] ?? [], 'id'),
            ])->all(),
            'pagador' => $pagador,
            'pendencias_cadastro' => $pagador ? $this->pendenciasCadastro($pagador) : ['responsável financeiro'],
            'contrato_existente' => $contrato ? ['id' => (int) $contrato->id, 'plano' => $contrato->plano] : null,
            'data_inicio' => $this->contratos->dataInicioSugerida($ano),
            'numero_sugerido' => $r->numero_matricula ?? $this->numeros->sugerir($ano),
            'rematricula' => $r && $r->status !== 'cancelada' ? $this->formatarRematricula($r) : null,
            'modo_asaas' => $this->financeiro->modoAsaas(),
        ];
    }

    /**
     * Rematrícula feita pela secretaria com a família presente. Gera o mesmo
     * que o aceite pelo app (contrato do ano novo, cobrança, número reservado)
     * e registra o contrato para assinatura em papel.
     *
     * $d: turma_destino_id, plano_id, desconto_padrao_id?, desconto_extra_tipo?, desconto_extra_valor?,
     *     desconto_extra_descricao?, data_inicio?, pagamento ('agora'|'depois'), forma_pagamento?, emitir_boleto?
     *
     * @throws \DomainException
     */
    public function rematricularPelaEscola(int $campanhaId, int $alunoId, array $d, int $usuarioId): array
    {
        $c = $this->campanhaParaAtendimento($campanhaId);
        $aluno = $this->alunoParaAtendimento($c, $alunoId);
        $ano = (int) $c->ano_destino;

        $destino = DB::table('turmas')->where('id', (int) ($d['turma_destino_id'] ?? 0))->first(['id', 'nome_turma', 'ano_letivo', 'status']);
        if (!$destino || (int) $destino->ano_letivo !== $ano || $destino->status !== 'Ativa') {
            throw new \DomainException("Escolha uma turma ativa de $ano.");
        }
        $pagador = $this->pagador($alunoId);
        if (!$pagador) {
            throw new \DomainException('O aluno não tem responsável vinculado. Cadastre o responsável financeiro antes.');
        }
        $pagamento = ($d['pagamento'] ?? 'depois') === 'agora' ? 'agora' : 'depois';
        $forma = $d['forma_pagamento'] ?? null;
        if ($pagamento === 'agora' && !in_array($forma, ['dinheiro', 'pix', 'transferencia', 'cartao_debito', 'cartao_credito'], true)) {
            throw new \DomainException('Informe a forma de pagamento recebida.');
        }

        $existenteMf = DB::table('matriculas_financeiras')->where('aluno_id', $alunoId)->where('ano_letivo', (string) $ano)
            ->where('status', 'ativa')->first(['id', 'plano_id']);
        // Sem cobrança no ano (bolsa integral, cobrança fora do sistema): vaga garantida sem contrato.
        $semContrato = !$existenteMf && !empty($d['sem_contrato']);
        $planoId = $existenteMf ? (int) $existenteMf->plano_id : (int) ($d['plano_id'] ?? 0);
        $plano = DB::table('planos_pagamento')->where('id', $planoId)->where('ativo', 1)->first(['id', 'nome', 'numero_parcelas', 'dia_vencimento']);
        if (!$plano && !$semContrato) {
            throw new \DomainException('Escolha o plano de pagamento.');
        }
        if ($semContrato) {
            return $this->rematricularSemContrato($c, $aluno, $destino, $pagador, trim((string) ($d['motivo_sem_contrato'] ?? '')), $usuarioId);
        }
        $vencimento = Carbon::today()->addDays((int) $c->prazo_pagamento_dias)->toDateString();
        if ($pagamento === 'agora') {
            $vencimento = Carbon::today()->toDateString();
        }

        $resultado = DB::transaction(function () use ($c, $alunoId, $aluno, $destino, $pagador, $plano, $existenteMf, $d, $usuarioId, $vencimento, $ano) {
            $existente = DB::table('rematriculas')->where('campanha_id', $c->id)->where('aluno_id', $alunoId)->lockForUpdate()->first();
            if ($existente && in_array($existente->status, ['aguardando_pagamento', 'confirmada'], true)) {
                throw new \DomainException('A rematrícula deste aluno já foi feita.');
            }

            // Contrato já feito em Financeiro → Contratos: a rematrícula usa ele.
            $mfId = $existenteMf ? (int) $existenteMf->id : (int) $this->matriculas->criar($usuarioId, [
                'aluno_id' => $alunoId, 'plano_id' => (int) $plano->id, 'ano_letivo' => (string) $ano,
                'data_inicio' => $d['data_inicio'] ?? $this->contratos->dataInicioSugerida($ano),
                'desconto_padrao_id' => !empty($d['desconto_padrao_id']) ? (int) $d['desconto_padrao_id'] : null,
                'desconto_extra_tipo' => !empty($d['desconto_extra_valor']) ? ($d['desconto_extra_tipo'] ?? null) : null,
                'desconto_extra_valor' => !empty($d['desconto_extra_valor']) ? (float) $d['desconto_extra_valor'] : null,
                'desconto_extra_descricao' => trim((string) ($d['desconto_extra_descricao'] ?? '')),
                'observacoes' => "Rematrícula {$c->ano_destino} — feita na secretaria.",
            ])['matricula_id'];

            $parcelaCobranca = $this->gerarCobranca($c, $mfId, $alunoId, $vencimento);
            $confirmada = $this->cobrancaQuitada($parcelaCobranca);

            // Contrato com os valores reais das parcelas geradas (descontos já aplicados).
            $p1 = DB::table('parcelas')->where('matricula_financeira_id', $mfId)->where('numero_parcela', '>=', 1)
                ->selectRaw('MIN(numero_parcela) as n, SUM(valor_final) as total')->first();
            $valorParcela = (float) DB::table('parcelas')->where('matricula_financeira_id', $mfId)->where('numero_parcela', (int) $p1->n)->value('valor_final');
            $html = $this->contrato->montar($c->contrato_modelo, $this->contrato->campos($c, [
                'responsavel' => $pagador, 'aluno' => (object) ['nome' => $aluno['nome'], 'data_nascimento' => $aluno['data_nascimento']],
                'turma' => $destino->nome_turma, 'plano' => $plano, 'valor_parcela' => $valorParcela,
                'valor_total' => round((float) $p1->total, 2), 'desconto' => $this->textoDescontos($mfId), 'data' => now(),
            ]));

            $dados = [
                'responsavel_id' => $pagador['id'], 'usuario_id' => DB::table('responsaveis')->where('id', $pagador['id'])->value('usuario_id'),
                'origem' => 'secretaria', 'atendido_por' => $usuarioId,
                'turma_origem_id' => $aluno['turma_id'], 'turma_destino_id' => (int) $destino->id,
                'numero_matricula' => $existente->numero_matricula ?? $this->numeros->proximo($ano),
                'plano_id' => (int) $plano->id, 'status' => $confirmada ? 'confirmada' : 'aguardando_pagamento', 'motivo_recusa' => null,
                'matricula_financeira_id' => $mfId, 'parcela_cobranca_id' => $parcelaCobranca, 'desconto_antecipado' => 0,
                'contrato_html' => $html, 'contrato_hash' => $this->contrato->hash($html),
                'aceite_em' => now(), 'aceite_ip' => null, 'aceite_user_agent' => null,
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

        $this->efetivarSeTardia($aluno, $resultado['id'], $usuarioId);

        // Recebido no balcão: baixa na hora (gera o recibo e confirma a rematrícula).
        $recibo = null;
        $boleto = false;
        if (!$resultado['confirmada'] && $resultado['parcela_id']) {
            if ($pagamento === 'agora') {
                $valor = (float) DB::table('parcelas')->where('id', $resultado['parcela_id'])->value('valor_final');
                $baixa = app(FinanceiroHelperService::class)->aplicarBaixaParcela($resultado['parcela_id'], $valor, now()->toDateString(),
                    $forma, "Rematrícula {$c->ano_destino} — recebido na secretaria", $usuarioId);
                $recibo = $baixa['numero_recibo'] ?? null;
            } elseif (($d['emitir_boleto'] ?? true) !== false) {
                $boleto = $this->emitirBoleto($resultado['parcela_id'], $usuarioId);
            }
        }

        $r = DB::table('rematriculas')->where('id', $resultado['id'])->first();
        return $this->formatarRematricula($r) + ['boleto_emitido' => $boleto, 'recibo' => $recibo];
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
            ->leftJoin('usuarios as q', 'q.id', '=', 'r.usuario_id')->leftJoin('usuarios as at', 'at.id', '=', 'r.atendido_por')
            ->where('r.id', $rematriculaId)->whereNotNull('r.contrato_html')
            ->first(['r.id', 'r.aluno_id', 'r.contrato_html', 'r.contrato_hash', 'r.aceite_em', 'r.aceite_ip', 'r.aceite_user_agent',
                'r.origem', 'r.numero_matricula', 'u.nome_completo as aluno', 'q.nome_completo as aceito_por', 'at.nome_completo as atendido_por']);
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

    /**
     * Rematrícula sem contrato: o aluno não terá cobrança no ano novo (fica
     * registrado como "sem cobrança" em Financeiro → Contratos). Vaga e número
     * garantidos, já confirmada.
     */
    private function rematricularSemContrato(object $c, array $aluno, object $destino, array $pagador, string $motivo, int $usuarioId): array
    {
        $ano = (int) $c->ano_destino;
        $id = DB::transaction(function () use ($c, $aluno, $destino, $pagador, $motivo, $usuarioId, $ano) {
            $existente = DB::table('rematriculas')->where('campanha_id', $c->id)->where('aluno_id', $aluno['id'])->lockForUpdate()->first();
            if ($existente && in_array($existente->status, ['aguardando_pagamento', 'confirmada'], true)) {
                throw new \DomainException('A rematrícula deste aluno já foi feita.');
            }
            $this->contratos->dispensar($aluno['id'], $ano, $motivo !== '' ? $motivo : 'Rematrícula sem cobrança', $usuarioId);
            $dados = [
                'responsavel_id' => $pagador['id'], 'usuario_id' => DB::table('responsaveis')->where('id', $pagador['id'])->value('usuario_id'),
                'origem' => 'secretaria', 'atendido_por' => $usuarioId,
                'turma_origem_id' => $aluno['turma_id'], 'turma_destino_id' => (int) $destino->id,
                'numero_matricula' => $existente->numero_matricula ?? $this->numeros->proximo($ano),
                'plano_id' => null, 'status' => 'confirmada', 'motivo_recusa' => null,
                'matricula_financeira_id' => null, 'parcela_cobranca_id' => null, 'desconto_antecipado' => 0,
                'contrato_html' => null, 'contrato_hash' => null, 'aceite_em' => now(), 'aceite_ip' => null, 'aceite_user_agent' => null,
                'confirmada_em' => now(), 'cancelada_por' => null, 'atualizado_em' => now(),
            ];
            if ($existente) {
                DB::table('rematriculas')->where('id', $existente->id)->update($dados);
                return (int) $existente->id;
            }
            return (int) DB::table('rematriculas')->insertGetId($dados + ['campanha_id' => $c->id, 'aluno_id' => $aluno['id']]);
        });
        $this->efetivarSeTardia($aluno, $id, $usuarioId);
        return $this->formatarRematricula(DB::table('rematriculas')->where('id', $id)->first()) + ['boleto_emitido' => false, 'recibo' => null];
    }

    /**
     * Parcela cobrada na hora, conforme a campanha: a 1ª do contrato (vencendo
     * em $vencimento) ou a taxa de matrícula (parcela nº 0, sem desconto).
     */
    private function gerarCobranca(object $c, int $mfId, int $alunoId, string $vencimento): ?int
    {
        if ($c->cobranca === 'primeira_parcela') {
            $p = DB::table('parcelas')->where('matricula_financeira_id', $mfId)->where('numero_parcela', 1)->first(['id', 'status']);
            if (!$p) {
                return null;
            }
            if (in_array($p->status, ['pendente', 'vencido'], true)) {
                DB::table('parcelas')->where('id', $p->id)->update(['data_vencimento' => $vencimento, 'status' => 'pendente']);
            }
            return (int) $p->id;
        }
        if ($c->cobranca === 'taxa') {
            $taxa = DB::table('parcelas')->where('matricula_financeira_id', $mfId)->where('numero_parcela', 0)
                ->whereNotIn('status', ['cancelado', 'negociado'])->value('id');
            return $taxa ? (int) $taxa : (int) DB::table('parcelas')->insertGetId([
                'matricula_financeira_id' => $mfId, 'aluno_id' => $alunoId, 'numero_parcela' => 0,
                'valor_original' => (float) $c->taxa_valor, 'desconto_aplicado' => 0, 'valor_final' => (float) $c->taxa_valor,
                'data_vencimento' => $vencimento, 'observacoes' => "Taxa de matrícula {$c->ano_destino}",
            ]);
        }
        return null;
    }

    /** Sem cobrança, cobrança zerada (bolsa) ou já paga: a rematrícula nasce confirmada. */
    private function cobrancaQuitada(?int $parcelaId): bool
    {
        if (!$parcelaId) {
            return true;
        }
        $p = DB::table('parcelas')->where('id', $parcelaId)->first(['valor_final', 'status']);
        return !$p || (float) $p->valor_final <= 0 || $p->status === 'pago';
    }

    /**
     * Boleto/Pix no Asaas, fora da transação: se o Asaas falhar, o contrato
     * fica feito e a família paga pela emissão automática ou na secretaria.
     */
    private function emitirBoleto(?int $parcelaId, int $usuarioId): bool
    {
        if (!$parcelaId || !$this->financeiro->modoAsaas()) {
            return false;
        }
        try {
            app(AsaasCobrancaService::class)->emitir($parcelaId, null, false, $usuarioId);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Rematrícula: falha ao emitir no Asaas', ['parcela' => $parcelaId, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    /** "10% Irmãos + R$ 50,00 (Rematrícula antecipada)" — para o campo {desconto} do contrato. */
    private function textoDescontos(int $mfId): string
    {
        $mf = DB::table('matriculas_financeiras as mf')->leftJoin('descontos_padrao as d', 'd.id', '=', 'mf.desconto_padrao_id')
            ->where('mf.id', $mfId)
            ->first(['d.nome', 'd.tipo', 'd.valor', 'mf.desconto_extra_tipo', 'mf.desconto_extra_valor', 'mf.desconto_extra_descricao']);
        $fmt = fn ($tipo, $valor) => $tipo === 'percentual'
            ? rtrim(rtrim(number_format((float) $valor, 2, ',', '.'), '0'), ',') . '%'
            : 'R$ ' . number_format((float) $valor, 2, ',', '.');
        $partes = [];
        if ($mf && $mf->nome) {
            $partes[] = $fmt($mf->tipo, $mf->valor) . ' (' . $mf->nome . ')';
        }
        if ($mf && $mf->desconto_extra_tipo && (float) $mf->desconto_extra_valor > 0) {
            $partes[] = $fmt($mf->desconto_extra_tipo, $mf->desconto_extra_valor) . ($mf->desconto_extra_descricao ? " ({$mf->desconto_extra_descricao})" : '');
        }
        return $partes ? implode(' + ', $partes) : 'sem desconto';
    }

    /** Campanha em que a secretaria pode atender: aberta ou encerrada (rematrícula fora do prazo). @throws \DomainException */
    private function campanhaParaAtendimento(int $campanhaId): object
    {
        $c = $this->disponivel() ? DB::table('rematricula_campanhas')->where('id', $campanhaId)->first() : null;
        if (!$c) {
            throw new \DomainException('Rematrícula não encontrada.');
        }
        if ($c->status === 'rascunho') {
            throw new \DomainException('Abra a rematrícula antes de atender as famílias.');
        }
        return $c;
    }

    /**
     * Aluno ativo numa turma ativa do ano anterior ao da campanha — ou que saiu
     * no encerramento desse ano (não renovou / formando) e voltou: aí a
     * rematrícula é "tardia" e já o coloca na turma nova. @throws \DomainException
     */
    private function alunoParaAtendimento(object $c, int $alunoId): array
    {
        $anoOrigem = (int) $c->ano_destino - 1;
        $a = DB::table('alunos as a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->join('turma_aluno as ta', 'ta.aluno_id', '=', 'a.id')->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->where('a.id', $alunoId)->where('u.ativo', 1)->where('t.status', 'Ativa')->where('t.ano_letivo', $anoOrigem)
            ->first(['a.id', 'u.nome_completo as nome', 'a.matricula', 'a.data_nascimento', 't.id as turma_id', 't.nome_turma as turma']);
        $tardia = false;
        if (!$a && $this->numeros->porAno()) {
            $naTurmaNova = DB::table('turma_aluno as ta')->join('turmas as t', 't.id', '=', 'ta.turma_id')
                ->where('ta.aluno_id', $alunoId)->where('t.ano_letivo', '>=', (int) $c->ano_destino)->exists();
            $a = $naTurmaNova ? null : DB::table('matriculas as m')->join('alunos as a', 'a.id', '=', 'm.aluno_id')
                ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')->join('turmas as t', 't.id', '=', 'm.turma_id')
                ->where('m.aluno_id', $alunoId)->where('m.ano_letivo', $anoOrigem)->whereIn('m.resultado', ['nao_renovou', 'concluiu'])
                ->orderByDesc('m.id')
                ->first(['a.id', 'u.nome_completo as nome', 'a.matricula', 'a.data_nascimento', 't.id as turma_id', 't.nome_turma as turma']);
            $tardia = (bool) $a;
        }
        if (!$a) {
            throw new \DomainException("O aluno precisa estar ativo numa turma de $anoOrigem (ou ter saído no encerramento de $anoOrigem).");
        }
        return [
            'id' => (int) $a->id, 'nome' => $a->nome, 'matricula' => $a->matricula, 'data_nascimento' => $a->data_nascimento,
            'turma_id' => (int) $a->turma_id, 'turma' => $a->turma, 'tardia' => $tardia,
        ];
    }

    /** Rematrícula de quem já tinha saído no encerramento: entra direto na turma nova. */
    private function efetivarSeTardia(array $aluno, int $rematriculaId, int $usuarioId): void
    {
        if ($aluno['tardia']) {
            app(\App\Services\Turmas\ViradaAnoService::class)->efetivarTardia($rematriculaId, $usuarioId);
        }
    }

    /** Turma do ano novo indicada para o aluno: a da campanha para a turma dele ou a série seguinte. */
    private function destinoSugerido(object $c, int $turmaOrigemId, string $turmaOrigem): ?int
    {
        $daCampanha = DB::table('rematricula_turmas')->where('campanha_id', $c->id)->where('turma_origem_id', $turmaOrigemId)->value('turma_destino_id');
        if ($daCampanha) {
            return (int) $daCampanha;
        }
        $sugestao = collect($this->sugerirTurmas((int) $c->ano_destino))->firstWhere('turma_origem_id', $turmaOrigemId);
        return $sugestao['turma_destino_id'] ?? null;
    }

    /** Alunos já na turma do ano novo + vagas garantidas por rematrícula ainda não efetivadas. */
    private function ocupacaoAnoNovo(int $ano): array
    {
        $out = [];
        $naTurma = DB::table('turma_aluno as ta')->join('turmas as t', 't.id', '=', 'ta.turma_id')->where('t.ano_letivo', $ano)
            ->selectRaw('ta.turma_id, COUNT(*) as n')->groupBy('ta.turma_id')->pluck('n', 'turma_id');
        $reservadas = DB::table('rematriculas')->whereIn('status', ['aguardando_pagamento', 'confirmada'])->whereNull('efetivada_em')
            ->whereNotNull('turma_destino_id')->selectRaw('turma_destino_id, COUNT(*) as n')->groupBy('turma_destino_id')->pluck('n', 'turma_destino_id');
        foreach ([$naTurma, $reservadas] as $lista) {
            foreach ($lista as $turmaId => $n) {
                $out[(int) $turmaId] = ($out[(int) $turmaId] ?? 0) + (int) $n;
            }
        }
        return $out;
    }

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
        // Alunos das turmas com destino padrão + quem foi rematriculado individualmente de outra turma.
        $total = DB::table('rematricula_turmas as rt')->join('turma_aluno as ta', 'ta.turma_id', '=', 'rt.turma_origem_id')
            ->join('alunos as a', 'a.id', '=', 'ta.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('rt.campanha_id', $campanhaId)->where('u.ativo', 1)->pluck('ta.aluno_id')
            ->merge(DB::table('rematriculas')->where('campanha_id', $campanhaId)->whereIn('status', ['aguardando_pagamento', 'confirmada'])->pluck('aluno_id'))
            ->map(fn ($v) => (int) $v)->unique()->count();
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
            'numero_matricula' => $r->numero_matricula ?? null, 'origem' => $r->origem ?? 'familia',
            'turma_destino' => $r->turma_destino_id ? DB::table('turmas')->where('id', $r->turma_destino_id)->value('nome_turma') : null,
            'efetivada' => !empty($r->efetivada_em),
        ];
    }

    /** Turma do ano seguinte com a mesma série (destino padrão de quem é retido), de preferência no mesmo turno. */
    public function turmaMesmaSerie(int $turmaOrigemId): ?int
    {
        $o = DB::table('turmas')->where('id', $turmaOrigemId)->first(['nome_turma', 'turno', 'ano_letivo']);
        if (!$o) {
            return null;
        }
        $serie = $this->serie($o->nome_turma);
        $candidatas = DB::table('turmas')->where('ano_letivo', (int) $o->ano_letivo + 1)->where('status', 'Ativa')
            ->get(['id', 'nome_turma', 'turno'])->filter(fn ($t) => $this->serie($t->nome_turma) === $serie);
        $t = $candidatas->firstWhere('turno', $o->turno) ?? $candidatas->first();
        return $t ? (int) $t->id : null;
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
