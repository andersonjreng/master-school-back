<?php

namespace App\Services\Notificacoes;

use App\Services\Financeiro\ContratoFinanceiroService;
use App\Services\Turmas\CalendarioEscolarService;
use App\Support\Papeis;
use App\Support\Permissoes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Avisos do sino para a equipe, por perfil/permissão. São "situações" e não
 * eventos: cada assunto vira UMA notificação-resumo ("3 planejamentos
 * aguardando aprovação"), recalculada ao abrir o sino (NotificacaoService::
 * sincronizar, no máximo a cada 10 min). Some sozinha quando a pendência
 * acaba e só volta a ficar não lida se a contagem AUMENTAR — diminuir
 * (alguém resolveu uma parte) não incomoda.
 *
 * Assuntos do dia (chamada pendente, pagamentos de hoje) levam a data na
 * chave; os de dias anteriores são apagados.
 *
 * - Professor: chamada pendente hoje nas turmas dele; avaliações dele sem nota.
 * - planejamento.aprovar: planejamentos aguardando aprovação.
 * - pedagogico.ver: turmas sem chamada hoje; alunos com 3+ faltas seguidas.
 * - pessoas.gerenciar: responsável que paga com cadastro incompleto.
 * - turmas.gerenciar: turmas ativas sem professor.
 * - financeiro.operar: pagamentos recebidos hoje; parcelas que venceram
 *   ontem sem pagamento; alunos sem contrato no ano.
 *
 * Eventos pontuais da equipe (planejamento devolvido) ficam no
 * NotificacaoService, gravados na hora.
 */
class AvisosEquipeService
{
    /** Chamada só é "pendente" a partir desta hora (as aulas começam de manhã). */
    private const HORA_CHAMADA = 10;
    /** Avaliação aplicada há mais de N dias sem nenhuma nota. */
    private const DIAS_SEM_NOTA = 3;

    /** @var array<string, bool> */
    private array $diaLetivo = [];

    public function __construct(
        private NotificacaoService $notificacoes,
        private CalendarioEscolarService $calendario,
        private ContratoFinanceiroService $contratos,
    ) {
    }

    public function sincronizar(int $usuarioId, array $funcoes): void
    {
        if (!Papeis::ehEquipe($funcoes)) {
            return;
        }
        $pode = fn (string $p) => Permissoes::pode($funcoes, $p);

        $partes = [];
        if (Papeis::ehProfessor($funcoes)) {
            $partes[] = fn () => $this->professor($usuarioId);
        }
        if ($pode(Permissoes::PLANEJAMENTO_APROVAR)) {
            $partes[] = fn () => $this->planejamentosPendentes($usuarioId);
        }
        if ($pode(Permissoes::PEDAGOGICO_VER)) {
            $partes[] = fn () => $this->chamadasDaEscola($usuarioId);
            $partes[] = fn () => $this->faltasSeguidas($usuarioId);
        }
        if ($pode(Permissoes::PESSOAS_GERENCIAR)) {
            $partes[] = fn () => $this->cadastrosIncompletos($usuarioId);
        }
        if ($pode(Permissoes::TURMAS_GERENCIAR)) {
            $partes[] = fn () => $this->turmasSemProfessor($usuarioId);
        }
        if ($pode(Permissoes::FINANCEIRO_OPERAR)) {
            $partes[] = fn () => $this->pagamentosDoDia($usuarioId);
            $partes[] = fn () => $this->vencidasOntem($usuarioId);
            $partes[] = fn () => $this->semContrato($usuarioId);
        }

        // Uma parte com problema não derruba as outras.
        foreach ($partes as $parte) {
            try {
                $parte();
            } catch (\Throwable $e) {
                Log::warning('Aviso da equipe não gerado: ' . $e->getMessage(), ['arquivo' => $e->getFile() . ':' . $e->getLine()]);
            }
        }
    }

    // ── Professor ────────────────────────────────────────────────────────────

    private function professor(int $usuarioId): void
    {
        $professorId = (int) DB::table('professores')->where('usuario_id', $usuarioId)->value('id');
        if (!$professorId) {
            return;
        }
        $turmas = DB::table('turma_professor_disciplina as tpd')->join('turmas as t', 't.id', '=', 'tpd.turma_id')
            ->where('tpd.professor_id', $professorId)->where('t.status', 'Ativa')
            ->distinct()->get(['t.id', 't.nome_turma']);

        // Chamada de hoje nas turmas dele.
        $pendentes = $this->chamadaPendente() ? $this->turmasSemChamada($turmas) : [];
        $this->situacaoDoDia($usuarioId, 'chamada', 'prof_chamada', count($pendentes),
            fn ($n) => $n === 1 ? 'Chamada de hoje pendente' : "Chamada de hoje pendente em $n turmas",
            implode(', ', array_slice($pendentes, 0, 4)) . (count($pendentes) > 4 ? '…' : ''),
            '/minhas-turmas');

        // Avaliações dele já aplicadas e ainda sem nenhuma nota.
        $semNota = DB::table('avaliacoes as a')->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
            ->join('turmas as t', 't.id', '=', 'a.turma_id')
            ->where('a.professor_id', $professorId)->where('t.status', 'Ativa')
            ->where('a.data_aplicacao', '<', now()->subDays(self::DIAS_SEM_NOTA)->toDateString())
            ->whereNotExists(fn ($q) => $q->from('notas as n')->whereColumn('n.avaliacao_id', 'a.id'))
            ->orderBy('a.data_aplicacao')->get(['d.nome_disciplina', 't.nome_turma', 'a.data_aplicacao']);
        $this->situacao($usuarioId, 'pendencia', 'prof_sem_notas', $semNota->count(),
            fn ($n) => $n === 1 ? '1 avaliação sua sem notas lançadas' : "$n avaliações suas sem notas lançadas",
            $semNota->take(3)->map(fn ($a) => "{$a->nome_disciplina} · {$a->nome_turma} (" . Carbon::parse($a->data_aplicacao)->format('d/m') . ')')->join(', '),
            '/minhas-turmas');
    }

    // ── Pedagógico ───────────────────────────────────────────────────────────

    private function planejamentosPendentes(int $usuarioId): void
    {
        $pendentes = DB::table('planos_aula as p')->join('turmas as t', 't.id', '=', 'p.turma_id')
            ->where('p.aprovacao', 'pendente')->where('t.status', 'Ativa')
            ->leftJoin('professores as pr', 'pr.id', '=', 'p.professor_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'pr.usuario_id')
            ->get(['u.nome_completo']);
        $profs = $pendentes->pluck('nome_completo')->filter()->map(fn ($n) => explode(' ', trim($n))[0])->unique()->values();
        $this->situacao($usuarioId, 'planejamento', 'planos_pendentes', $pendentes->count(),
            fn ($n) => $n === 1 ? '1 planejamento aguardando aprovação' : "$n planejamentos aguardando aprovação",
            $profs->count() ? 'De ' . $profs->take(3)->join(', ') . ($profs->count() > 3 ? ' e outros' : '') : '',
            '/planejamento', ['aba' => 'aprovacao']);
    }

    private function chamadasDaEscola(int $usuarioId): void
    {
        $pendentes = [];
        if ($this->chamadaPendente()) {
            $turmas = DB::table('turmas')->where('status', 'Ativa')->where('ano_letivo', now()->year)->get(['id', 'nome_turma']);
            $pendentes = $this->turmasSemChamada($turmas);
        }
        $this->situacaoDoDia($usuarioId, 'chamada', 'chamadas_escola', count($pendentes),
            fn ($n) => $n === 1 ? '1 turma sem chamada hoje' : "$n turmas sem chamada hoje",
            implode(', ', array_slice($pendentes, 0, 4)) . (count($pendentes) > 4 ? '…' : ''),
            '/frequencia/relatorio');
    }

    /** Alunos (de turmas ativas) cuja sequência mais recente de chamadas tem 3+ faltas. */
    private function faltasSeguidas(int $usuarioId): void
    {
        $alunos = DB::table('turma_aluno as ta')->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->join('alunos as a', 'a.id', '=', 'ta.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('t.status', 'Ativa')->where('u.ativo', 1)->pluck('ta.aluno_id')->all();
        $comSequencia = [];
        if ($alunos) {
            DB::table('frequencia')->whereIn('aluno_id', $alunos)->where('data', '>=', now()->subDays(30)->toDateString())
                ->orderByDesc('data')->get(['aluno_id', 'presente'])->groupBy('aluno_id')
                ->each(function ($linhas, $alunoId) use (&$comSequencia) {
                    $seq = 0;
                    foreach ($linhas as $l) {
                        if ((int) $l->presente === 1) {
                            break;
                        }
                        $seq++;
                    }
                    if ($seq >= 3) {
                        $comSequencia[] = (int) $alunoId;
                    }
                });
        }
        $this->situacao($usuarioId, 'faltas', 'faltas_seguidas', count($comSequencia),
            fn ($n) => $n === 1 ? '1 aluno com 3+ faltas seguidas' : "$n alunos com 3+ faltas seguidas",
            'Risco de evasão — vale contato com a família.',
            '/frequencia/relatorio');
    }

    // ── Secretaria ───────────────────────────────────────────────────────────

    /** Quem paga (mesma regra do boleto) sem CPF ou endereço completo: não dá para emitir boleto. */
    private function cadastrosIncompletos(int $usuarioId): void
    {
        $pagadores = DB::table('turma_aluno as ta')->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->where('t.status', 'Ativa')
            ->selectRaw('(SELECT ar.responsavel_id FROM aluno_responsavel ar WHERE ar.aluno_id = ta.aluno_id ORDER BY ar.principal DESC, ar.responsavel_id LIMIT 1) AS rid')
            ->pluck('rid')->filter()->unique()->values()->all();
        $n = $pagadores ? DB::table('responsaveis')->whereIn('id', $pagadores)
            ->where(fn ($q) => $q->whereNull('cpf')->orWhere('cpf', '')->orWhereNull('endereco')->orWhere('endereco', '')
                ->orWhereNull('cep')->orWhere('cep', '')->orWhereNull('numero')->orWhere('numero', ''))
            ->count() : 0;
        $this->situacao($usuarioId, 'cadastro', 'cadastro_incompleto', $n,
            fn ($n) => $n === 1 ? '1 responsável financeiro com cadastro incompleto' : "$n responsáveis financeiros com cadastro incompleto",
            'Sem CPF e endereço completo não sai boleto.',
            '/pessoas', ['aba' => 'responsaveis']);
    }

    private function turmasSemProfessor(int $usuarioId): void
    {
        $turmas = DB::table('turmas as t')->where('t.status', 'Ativa')
            ->whereExists(fn ($q) => $q->from('turma_aluno as ta')->whereColumn('ta.turma_id', 't.id'))
            ->whereNotExists(fn ($q) => $q->from('turma_professor_disciplina as tpd')->whereColumn('tpd.turma_id', 't.id'))
            ->pluck('t.nome_turma');
        $this->situacao($usuarioId, 'turmas', 'turmas_sem_professor', $turmas->count(),
            fn ($n) => $n === 1 ? '1 turma sem professor' : "$n turmas sem professor",
            $turmas->take(4)->join(', ') . ($turmas->count() > 4 ? '…' : ''),
            '/turmas');
    }

    // ── Financeiro ───────────────────────────────────────────────────────────

    /** Pagamentos de hoje que a pessoa não lançou ela mesma (Asaas, retorno do banco, colegas). */
    private function pagamentosDoDia(int $usuarioId): void
    {
        $r = DB::table('baixas_pagamento')->where('data_pagamento', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('registrado_por')->orWhere('registrado_por', '!=', $usuarioId))
            ->selectRaw('COUNT(*) AS qtd, COALESCE(SUM(valor_pago), 0) AS valor')->first();
        $this->situacaoDoDia($usuarioId, 'pagamento', 'pagamentos_dia', (int) $r->qtd,
            fn ($n) => ($n === 1 ? '1 pagamento recebido hoje' : "$n pagamentos recebidos hoje"),
            'R$ ' . number_format((float) $r->valor, 2, ',', '.') . ' no total',
            '/financeiro/cobrancas');
    }

    private function vencidasOntem(int $usuarioId): void
    {
        $ontem = now()->subDay()->toDateString();
        $r = DB::table('parcelas')->where('data_vencimento', $ontem)->whereIn('status', ['pendente', 'vencido'])
            ->selectRaw('COUNT(*) AS qtd, COUNT(DISTINCT aluno_id) AS alunos, COALESCE(SUM(valor_final), 0) AS valor')->first();
        $this->situacaoDoDia($usuarioId, 'inadimplencia', 'vencidas_ontem', (int) $r->qtd,
            fn ($n) => $n === 1 ? '1 parcela venceu ontem sem pagamento' : "$n parcelas venceram ontem sem pagamento",
            "{$r->alunos} aluno(s) · R$ " . number_format((float) $r->valor, 2, ',', '.'),
            '/financeiro/cobrancas', ['aba' => 'inadimplencia']);
    }

    private function semContrato(int $usuarioId): void
    {
        $ano = (int) now()->year;
        $n = $this->contratos->contarSemContrato($ano);
        $this->situacao($usuarioId, 'contrato', "sem_contrato:$ano", $n,
            fn ($n) => $n === 1 ? "1 aluno sem contrato em $ano" : "$n alunos sem contrato em $ano",
            'Não geram mensalidade.',
            '/financeiro/contratos', ['aba' => 'sem-contrato', 'ano' => $ano]);
    }

    // ── Base ─────────────────────────────────────────────────────────────────

    /** Hoje é dia letivo e já passou da hora em que a chamada deveria estar feita? */
    private function chamadaPendente(): bool
    {
        if ((int) now()->format('H') < self::HORA_CHAMADA) {
            return false;
        }
        $hoje = now()->toDateString();
        return $this->diaLetivo[$hoje] ??= $this->calendario->ehLetivo($hoje, $this->calendario->mapa((int) now()->year));
    }

    /** @return string[] nomes das turmas (com alunos) sem nenhuma frequência lançada hoje */
    private function turmasSemChamada($turmas): array
    {
        $hoje = now()->toDateString();
        $saida = [];
        foreach ($turmas as $t) {
            $alunos = DB::table('turma_aluno')->where('turma_id', $t->id)->pluck('aluno_id');
            if ($alunos->isEmpty()) {
                continue;
            }
            if (!DB::table('frequencia')->whereIn('aluno_id', $alunos)->where('data', $hoje)->exists()) {
                $saida[] = $t->nome_turma;
            }
        }
        return $saida;
    }

    /** Assunto do dia: a chave leva a data e os dias anteriores são apagados. */
    private function situacaoDoDia(int $usuarioId, string $tipo, string $prefixo, int $n, callable $titulo, string $detalhe, string $link, ?array $query = null): void
    {
        DB::table('notificacoes')->where('usuario_id', $usuarioId)->where('chave', 'like', "$prefixo:%")
            ->where('chave', '!=', $prefixo . ':' . now()->toDateString())->delete();
        $this->situacao($usuarioId, $tipo, $prefixo . ':' . now()->toDateString(), $n, $titulo, $detalhe, $link, $query);
    }

    /**
     * Grava/atualiza a notificação-resumo de um assunto (ou apaga, se n = 0).
     * Volta a "não lida" só quando a contagem aumenta.
     */
    private function situacao(int $usuarioId, string $tipo, string $chave, int $n, callable $titulo, string $detalhe, string $link, ?array $query = null): void
    {
        $atual = DB::table('notificacoes')->where('usuario_id', $usuarioId)->where('chave', $chave)->first(['id', 'titulo']);
        if ($n <= 0) {
            if ($atual) {
                DB::table('notificacoes')->where('id', $atual->id)->delete();
            }
            return;
        }
        // Contagem que estava no título ("Chamada de hoje pendente" sem número = 1).
        $antes = $atual ? (preg_match('/\d+/', $atual->titulo, $m) ? (int) $m[0] : 1) : 0;
        $this->notificacoes->gravar([$usuarioId], $tipo, $chave, null, $titulo($n), $detalhe !== '' ? $detalhe : null,
            $link, $query, reabrir: !$atual || $n > $antes);
    }
}
