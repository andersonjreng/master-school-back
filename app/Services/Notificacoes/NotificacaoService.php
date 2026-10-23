<?php

namespace App\Services\Notificacoes;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Notificações do sino (tabela `notificacoes`), por usuário.
 *
 * Dois jeitos de nascer:
 * - por evento, chamado de quem grava: nota lançada/corrigida, avaliação
 *   marcada/remarcada/excluída, falta registrada (ou desfeita);
 * - na hora de ler (sincronizarFamilia): boleto perto de vencer ou vencido
 *   e lembrete de avaliação amanhã — coisas que dependem do relógio, sem
 *   precisar de cron.
 *
 * `chave` identifica o assunto e é única por usuário: o mesmo assunto
 * ATUALIZA a notificação (e a marca como não lida de novo quando reabrir),
 * nunca duplica. Destinatários: responsáveis do aluno (boleto, só o
 * financeiro/principal) e o próprio aluno quando ele tem acesso ao portal.
 *
 * Nada aqui pode quebrar o fluxo principal: lançar nota/chamada continua
 * funcionando mesmo se a notificação falhar (erro só vai para o log).
 */
class NotificacaoService
{
    /** Chamada lançada com mais atraso que isso não avisa a família (evita enxurrada em lançamento retroativo). */
    private const DIAS_FALTA_RECENTE = 7;
    private const DIAS_BOLETO_ANTES = 3;
    private const MINUTOS_SINCRONIA = 10;

    private ?bool $tabelaExiste = null;

    // ── Eventos ──────────────────────────────────────────────────────────────

    /** Notas lançadas ou corrigidas numa avaliação (uma notificação por aluno). */
    public function notasLancadas(int $avaliacaoId, array $alunoIds): void
    {
        $this->seguro(function () use ($avaliacaoId, $alunoIds) {
            $av = $this->avaliacao($avaliacaoId);
            if (!$av || !$alunoIds) {
                return;
            }
            $notas = DB::table('notas')->where('avaliacao_id', $avaliacaoId)->whereIn('aluno_id', $alunoIds)
                ->pluck('nota_valor', 'aluno_id');
            $dest = $this->destinatarios(array_keys($notas->all()));
            foreach ($notas as $alunoId => $valor) {
                $aluno = $dest['nomes'][$alunoId] ?? null;
                if (!$aluno) {
                    continue;
                }
                $this->gravar($dest['usuarios'][$alunoId] ?? [], 'nota', "nota:$avaliacaoId:$alunoId", (int) $alunoId,
                    "Nota de {$av->disciplina}: " . $this->numero($valor) . ' de ' . $this->numero($av->valor_maximo),
                    $this->juntar([$aluno, $av->nome]),
                    '/boletim', ['aluno_id' => (int) $alunoId]);
            }
        });
    }

    /** Avaliação criada ou alterada: avisa a turma se ainda vai acontecer. */
    public function avaliacaoMarcada(int $avaliacaoId): void
    {
        $this->seguro(function () use ($avaliacaoId) {
            $av = $this->avaliacao($avaliacaoId);
            if (!$av) {
                return;
            }
            if (!$av->data_aplicacao || $av->data_aplicacao < now()->toDateString()) {
                // Passou para o passado (remarcada para trás): o aviso não faz mais sentido.
                $this->apagarPorPrefixo("avaliacao:$avaliacaoId:");
                return;
            }
            $alunos = DB::table('turma_aluno')->where('turma_id', $av->turma_id)->pluck('aluno_id')->map(fn ($id) => (int) $id)->all();
            $dest = $this->destinatarios($alunos);
            $quando = Carbon::parse($av->data_aplicacao)->format('d/m');
            foreach ($alunos as $alunoId) {
                $aluno = $dest['nomes'][$alunoId] ?? null;
                if (!$aluno) {
                    continue;
                }
                $this->gravar($dest['usuarios'][$alunoId] ?? [], 'avaliacao', "avaliacao:$avaliacaoId:$alunoId", $alunoId,
                    "Avaliação de {$av->disciplina} em $quando",
                    $this->juntar([$aluno, $av->nome]),
                    '/avaliacoes', ['turma_id' => (int) $av->turma_id, 'nome_turma' => $av->turma]);
            }
        });
    }

    public function avaliacaoRemovida(int $avaliacaoId): void
    {
        $this->seguro(fn () => $this->apagarPorPrefixo(["avaliacao:$avaliacaoId:", "avaliacao_amanha:$avaliacaoId:"]));
    }

    /**
     * Chamada salva: falta recente vira notificação; presença (ou falta
     * corrigida) apaga a notificação daquele dia.
     *
     * @param array<int, array{aluno_id:int|string, presente?:mixed, justificativa?:?string}> $itens
     */
    public function frequenciaLancada(string $data, array $itens): void
    {
        $this->seguro(function () use ($data, $itens) {
            $presentes = [];
            $ausentes = [];
            foreach ($itens as $i) {
                $id = (int) ($i['aluno_id'] ?? 0);
                if ($id) {
                    if (!empty($i['presente'])) {
                        $presentes[] = $id;
                    } else {
                        $ausentes[$id] = trim((string) ($i['justificativa'] ?? ''));
                    }
                }
            }
            if ($presentes) {
                DB::table('notificacoes')->whereIn('chave', array_map(fn ($id) => "falta:$id:$data", $presentes))->delete();
            }
            if (!$ausentes || $data < now()->subDays(self::DIAS_FALTA_RECENTE)->toDateString()) {
                return;
            }
            $dest = $this->destinatarios(array_keys($ausentes));
            $dia = Carbon::parse($data)->format('d/m');
            foreach ($ausentes as $alunoId => $justificativa) {
                $aluno = $dest['nomes'][$alunoId] ?? null;
                if (!$aluno) {
                    continue;
                }
                $this->gravar($dest['usuarios'][$alunoId] ?? [], 'falta', "falta:$alunoId:$data", $alunoId,
                    "Falta registrada em $dia",
                    $this->juntar([$aluno, $justificativa !== '' ? "Justificativa: $justificativa" : null]),
                    '/frequencia/relatorio', ['aluno_id' => $alunoId],
                    // Corrigir só a justificativa não precisa avisar de novo.
                    reabrir: false);
            }
        });
    }

    public function chamadaExcluida(string $data, array $alunoIds): void
    {
        $this->seguro(function () use ($data, $alunoIds) {
            if ($alunoIds) {
                DB::table('notificacoes')->whereIn('chave', array_map(fn ($id) => "falta:$id:$data", $alunoIds))->delete();
            }
        });
    }

    // ── Na hora de ler: boletos e lembretes (dependem do relógio) ───────────

    /**
     * Para quem é família: boleto vencendo em até 3 dias ou vencido e
     * avaliação amanhã. Roda no máximo a cada 10 min por usuário.
     */
    public function sincronizarFamilia(int $usuarioId): void
    {
        $this->seguro(function () use ($usuarioId) {
            $chaveCache = 'notif_sync:' . config('database.connections.mysql.database') . ":$usuarioId";
            if (Cache::get($chaveCache)) {
                return;
            }
            Cache::put($chaveCache, 1, now()->addMinutes(self::MINUTOS_SINCRONIA));

            $vinculos = DB::table('responsaveis as r')->join('aluno_responsavel as ar', 'ar.responsavel_id', '=', 'r.id')
                ->where('r.usuario_id', $usuarioId)->get(['ar.aluno_id', 'r.id as responsavel_id']);
            $alunoProprio = DB::table('alunos')->where('usuario_id', $usuarioId)->value('id');
            $alunos = $vinculos->pluck('aluno_id')->map(fn ($id) => (int) $id)->all();
            if ($alunoProprio) {
                $alunos[] = (int) $alunoProprio;
            }
            if (!$alunos) {
                return;
            }
            $nomes = $this->nomesAlunos($alunos);

            // Boletos: só para quem paga — mesma regra do boleto
            // (FinanceiroHelperService::buscarResponsavelPagador): o principal
            // ou, sem principal marcado, o primeiro responsável vinculado.
            $financeiros = [];
            foreach ($vinculos as $v) {
                $pagador = DB::table('aluno_responsavel')->where('aluno_id', $v->aluno_id)
                    ->orderByDesc('principal')->orderBy('responsavel_id')->value('responsavel_id');
                if ((int) $pagador === (int) $v->responsavel_id) {
                    $financeiros[] = (int) $v->aluno_id;
                }
            }
            if ($financeiros) {
                $hoje = now()->toDateString();
                $limite = now()->addDays(self::DIAS_BOLETO_ANTES)->toDateString();
                $parcelas = DB::table('parcelas')->whereIn('aluno_id', $financeiros)
                    ->whereIn('status', ['pendente', 'vencido'])->where('data_vencimento', '<=', $limite)
                    ->get(['id', 'aluno_id', 'numero_parcela', 'valor_final', 'data_vencimento']);
                foreach ($parcelas as $p) {
                    $vencida = $p->data_vencimento < $hoje;
                    $titulo = $vencida
                        ? 'Mensalidade em atraso'
                        : ($p->data_vencimento === $hoje ? 'Mensalidade vence hoje' : 'Mensalidade vence em ' . Carbon::parse($p->data_vencimento)->format('d/m'));
                    $this->gravar([$usuarioId], 'boleto', "boleto:{$p->id}:" . ($vencida ? 'vencido' : 'vencendo'), (int) $p->aluno_id,
                        $titulo,
                        $this->juntar([$nomes[$p->aluno_id] ?? null, "Parcela {$p->numero_parcela}", 'R$ ' . number_format((float) $p->valor_final, 2, ',', '.'),
                            'venc. ' . Carbon::parse($p->data_vencimento)->format('d/m')]),
                        '/meus-pagamentos', ['aluno_id' => (int) $p->aluno_id], reabrir: false);
                }
                // Parcela paga/cancelada depois do aviso: some do sino.
                $abertas = DB::table('notificacoes')->where('usuario_id', $usuarioId)->where('tipo', 'boleto')->pluck('chave', 'id');
                $apagar = [];
                foreach ($abertas as $id => $chave) {
                    $parcelaId = (int) explode(':', $chave)[1];
                    if (!$parcelas->contains('id', $parcelaId)) {
                        $apagar[] = $id;
                    }
                }
                if ($apagar) {
                    DB::table('notificacoes')->whereIn('id', $apagar)->delete();
                }
            }

            // Lembrete: avaliação amanhã nas turmas dos dependentes.
            $amanha = now()->addDay()->toDateString();
            $avs = DB::table('avaliacoes as a')->join('turma_aluno as ta', 'ta.turma_id', '=', 'a.turma_id')
                ->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
                ->join('turmas as t', 't.id', '=', 'a.turma_id')
                ->whereIn('ta.aluno_id', $alunos)->where('a.data_aplicacao', $amanha)
                ->get(['a.id', 'ta.aluno_id', 'a.descricao', 'a.turma_id', 't.nome_turma', 'd.nome_disciplina']);
            foreach ($avs as $a) {
                $this->gravar([$usuarioId], 'avaliacao', "avaliacao_amanha:{$a->id}:{$a->aluno_id}", (int) $a->aluno_id,
                    "Amanhã tem avaliação de {$a->nome_disciplina}",
                    $this->juntar([$nomes[$a->aluno_id] ?? null, $a->descricao ?: null]),
                    '/avaliacoes', ['turma_id' => (int) $a->turma_id, 'nome_turma' => $a->nome_turma], reabrir: false);
            }
        });
    }

    // ── Leitura ──────────────────────────────────────────────────────────────

    /** @return array{nao_lidas: int, itens: array<int, array<string, mixed>>} */
    public function doUsuario(int $usuarioId, int $limite = 15): array
    {
        if (!$this->disponivel()) {
            return ['nao_lidas' => 0, 'itens' => []];
        }
        $itens = DB::table('notificacoes')->where('usuario_id', $usuarioId)
            ->orderByRaw('lida_em IS NULL DESC')->orderByDesc('atualizado_em')->limit($limite)
            ->get(['id', 'tipo', 'titulo', 'detalhe', 'link', 'link_query', 'atualizado_em', 'lida_em'])
            ->map(fn ($n) => [
                'id' => (int) $n->id, 'tipo' => $n->tipo, 'titulo' => $n->titulo, 'detalhe' => $n->detalhe,
                'link' => $n->link, 'query' => $n->link_query ? json_decode($n->link_query, true) : null,
                'quando' => $n->atualizado_em, 'lida' => $n->lida_em !== null,
            ])->all();

        return [
            'nao_lidas' => DB::table('notificacoes')->where('usuario_id', $usuarioId)->whereNull('lida_em')->count(),
            'itens' => $itens,
        ];
    }

    public function marcarLida(int $usuarioId, int $id): void
    {
        if ($this->disponivel()) {
            DB::table('notificacoes')->where('id', $id)->where('usuario_id', $usuarioId)->whereNull('lida_em')->update(['lida_em' => now()]);
        }
    }

    public function marcarTodasLidas(int $usuarioId): int
    {
        return $this->disponivel()
            ? DB::table('notificacoes')->where('usuario_id', $usuarioId)->whereNull('lida_em')->update(['lida_em' => now()])
            : 0;
    }

    /** Limpeza (comando logs:limpar): lidas há mais de 60 dias e qualquer uma com mais de 180. */
    public function limparAntigas(): int
    {
        if (!$this->disponivel()) {
            return 0;
        }
        return DB::table('notificacoes')
            ->where(fn ($q) => $q->where('lida_em', '<', now()->subDays(60))->orWhere('atualizado_em', '<', now()->subDays(180)))
            ->delete();
    }

    public function disponivel(): bool
    {
        // Escola que ainda não rodou a migration: o resto do sistema segue normal.
        return $this->tabelaExiste ??= Schema::hasTable('notificacoes');
    }

    // ── Base ─────────────────────────────────────────────────────────────────

    private function gravar(array $usuarioIds, string $tipo, string $chave, ?int $alunoId, string $titulo, ?string $detalhe,
        ?string $link, ?array $query, bool $reabrir = true): void
    {
        $usuarioIds = array_values(array_unique(array_filter(array_map('intval', $usuarioIds))));
        if (!$usuarioIds) {
            return;
        }
        $agora = now()->toDateTimeString();
        $linhas = [];
        $valores = [];
        foreach ($usuarioIds as $uid) {
            $linhas[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($valores, $uid, $tipo, $chave, $alunoId, mb_substr($titulo, 0, 160), $detalhe !== null ? mb_substr($detalhe, 0, 255) : null,
                $link, $query ? json_encode($query, JSON_UNESCAPED_UNICODE) : null, $agora, $agora);
        }
        // Mesmo assunto: atualiza o texto; se mudou de fato e é para reabrir, volta a "não lida".
        $reabre = $reabrir
            ? 'lida_em = IF(titulo <=> VALUES(titulo) AND detalhe <=> VALUES(detalhe), lida_em, NULL),'
            : '';
        DB::statement('INSERT INTO notificacoes (usuario_id, tipo, chave, aluno_id, titulo, detalhe, link, link_query, criado_em, atualizado_em)
            VALUES ' . implode(', ', $linhas) . "
            ON DUPLICATE KEY UPDATE
                $reabre
                atualizado_em = IF(titulo <=> VALUES(titulo) AND detalhe <=> VALUES(detalhe), atualizado_em, VALUES(atualizado_em)),
                titulo = VALUES(titulo), detalhe = VALUES(detalhe), link = VALUES(link), link_query = VALUES(link_query)", $valores);
    }

    /**
     * Quem recebe por aluno: responsáveis ativos + o próprio aluno, se ele
     * tem acesso ao portal. Também devolve o primeiro nome de cada aluno.
     *
     * @return array{usuarios: array<int, int[]>, nomes: array<int, string>}
     */
    private function destinatarios(array $alunoIds): array
    {
        $alunoIds = array_values(array_unique(array_map('intval', $alunoIds)));
        $out = ['usuarios' => [], 'nomes' => $this->nomesAlunos($alunoIds)];
        if (!$alunoIds) {
            return $out;
        }
        DB::table('aluno_responsavel as ar')->join('responsaveis as r', 'r.id', '=', 'ar.responsavel_id')
            ->join('usuarios as u', 'u.id', '=', 'r.usuario_id')
            ->whereIn('ar.aluno_id', $alunoIds)->where('u.ativo', 1)
            ->get(['ar.aluno_id', 'u.id'])
            ->each(function ($r) use (&$out) {
                $out['usuarios'][(int) $r->aluno_id][] = (int) $r->id;
            });
        DB::table('alunos as a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->whereIn('a.id', $alunoIds)->where('u.ativo', 1)->where('u.acesso_portal', 1)
            ->get(['a.id', 'u.id as usuario_id'])
            ->each(function ($r) use (&$out) {
                $out['usuarios'][(int) $r->id][] = (int) $r->usuario_id;
            });
        return $out;
    }

    /** @return array<int, string> aluno_id => primeiro nome (só alunos ativos) */
    private function nomesAlunos(array $alunoIds): array
    {
        if (!$alunoIds) {
            return [];
        }
        return DB::table('alunos as a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->whereIn('a.id', $alunoIds)->where('u.ativo', 1)
            ->pluck('u.nome_completo', 'a.id')
            ->map(fn ($nome) => explode(' ', trim((string) $nome))[0])
            ->all();
    }

    private function avaliacao(int $id): ?object
    {
        $av = DB::table('avaliacoes as a')
            ->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
            ->join('turmas as t', 't.id', '=', 'a.turma_id')
            ->leftJoin('tipos_avaliacao as ta', 'ta.id', '=', 'a.tipo_avaliacao_id')
            ->where('a.id', $id)
            ->first(['a.id', 'a.turma_id', 'a.descricao', 'a.data_aplicacao', 'a.valor_maximo', 'd.nome_disciplina as disciplina',
                't.nome_turma as turma', 'ta.nome_tipo']);
        if ($av) {
            $av->nome = trim((string) $av->descricao) !== '' ? trim($av->descricao) : ($av->nome_tipo ?: 'Avaliação');
        }
        return $av;
    }

    private function apagarPorPrefixo(string|array $prefixos): void
    {
        DB::table('notificacoes')->where(function ($q) use ($prefixos) {
            foreach ((array) $prefixos as $p) {
                $q->orWhere('chave', 'like', str_replace(['%', '_'], ['\%', '\_'], $p) . '%');
            }
        })->delete();
    }

    private function numero($v): string
    {
        $n = round((float) $v, 2);
        return str_replace('.', ',', rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'));
    }

    private function juntar(array $partes): string
    {
        return implode(' · ', array_filter($partes, fn ($p) => $p !== null && $p !== ''));
    }

    private function seguro(callable $fn): void
    {
        if (!$this->disponivel()) {
            return;
        }
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Notificação não gerada: ' . $e->getMessage(), ['arquivo' => $e->getFile() . ':' . $e->getLine()]);
        }
    }
}
