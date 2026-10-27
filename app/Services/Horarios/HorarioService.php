<?php

namespace App\Services\Horarios;

use App\Services\Notificacoes\NotificacaoService;
use App\Services\Turmas\CalendarioEscolarService;
use App\Services\Turmas\PeriodoLetivoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quadro de horários da escola.
 *
 * Modelo (o "sinal" da escola) → faixas de horário de um turno (aulas e
 * intervalos). Cada turma usa um modelo e escolhe os dias da semana; a grade
 * é dia × faixa → disciplina (+ professor, se não for o do vínculo, e sala).
 * Um professor em duas turmas no mesmo horário é sinalizado (não bloqueia:
 * a escola decide). A carga semanal do vínculo professor × disciplina é a meta
 * de aulas da disciplina na semana.
 *
 * Leitura para todos (a família vê a grade dos filhos); edição com a
 * permissão horarios.editar.
 */
class HorarioService
{
    public const DIAS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];

    public function __construct(private NotificacaoService $notificacoes)
    {
    }

    public function disponivel(): bool
    {
        // Escola que ainda não rodou a migration 2026_10_28_100000: a tela avisa e o resto segue normal.
        return Schema::hasTable('horario_aulas');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Modelos (faixas de horário)
    // ═════════════════════════════════════════════════════════════════════

    public function modelos(): array
    {
        $faixas = DB::table('horario_faixas')->orderBy('modelo_id')->orderBy('ordem')->get()->groupBy('modelo_id');
        $uso = DB::table('horario_turmas')->selectRaw('modelo_id, COUNT(*) as n')->groupBy('modelo_id')->pluck('n', 'modelo_id');
        return DB::table('horario_modelos')->orderBy('nome')->get()->map(fn ($m) => [
            'id' => (int) $m->id, 'nome' => $m->nome, 'turno' => $m->turno, 'turmas' => (int) ($uso[$m->id] ?? 0),
            'faixas' => ($faixas[$m->id] ?? collect())->map(fn ($f) => $this->faixa($f))->values()->all(),
        ])->all();
    }

    /**
     * Cria ou atualiza um modelo. Faixas que vêm com id são mantidas (a grade
     * das turmas continua ligada a elas); as que saem apagam as aulas delas.
     *
     * @param array{nome: string, turno?: ?string, faixas: array<int, array{id?: ?int, inicio: string, fim: string, tipo?: string, rotulo?: ?string}>} $d
     * @throws \DomainException
     */
    public function salvarModelo(?int $id, array $d): int
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            throw new \DomainException('Dê um nome ao horário (ex.: "Manhã — Fundamental").');
        }
        $faixas = collect($d['faixas'] ?? [])->map(fn ($f) => [
            'id' => !empty($f['id']) ? (int) $f['id'] : null,
            'inicio' => $this->hora($f['inicio'] ?? ''), 'fim' => $this->hora($f['fim'] ?? ''),
            'tipo' => ($f['tipo'] ?? 'aula') === 'intervalo' ? 'intervalo' : 'aula',
            'rotulo' => trim((string) ($f['rotulo'] ?? '')) ?: null,
        ])->sortBy('inicio')->values();

        if ($faixas->where('tipo', 'aula')->isEmpty()) {
            throw new \DomainException('Inclua ao menos um horário de aula.');
        }
        foreach ($faixas as $i => $f) {
            if (!$f['inicio'] || !$f['fim'] || $f['inicio'] >= $f['fim']) {
                throw new \DomainException('Confira os horários: cada faixa precisa de início antes do fim.');
            }
            if ($i > 0 && $f['inicio'] < $faixas[$i - 1]['fim']) {
                throw new \DomainException("Os horários {$this->hm($faixas[$i - 1]['inicio'])}–{$this->hm($faixas[$i - 1]['fim'])} e "
                    . "{$this->hm($f['inicio'])}–{$this->hm($f['fim'])} se sobrepõem.");
            }
        }

        return DB::transaction(function () use ($id, $nome, $d, $faixas) {
            $dados = ['nome' => mb_substr($nome, 0, 80), 'turno' => ($d['turno'] ?? null) ?: null];
            if ($id) {
                if (!DB::table('horario_modelos')->where('id', $id)->exists()) {
                    throw new \DomainException('Horário não encontrado.');
                }
                DB::table('horario_modelos')->where('id', $id)->update($dados);
            } else {
                $id = (int) DB::table('horario_modelos')->insertGetId($dados);
            }
            $manter = $faixas->pluck('id')->filter()->all();
            DB::table('horario_faixas')->where('modelo_id', $id)->whereNotIn('id', $manter ?: [0])->delete();
            foreach ($faixas as $ordem => $f) {
                $linha = ['modelo_id' => $id, 'ordem' => $ordem + 1, 'inicio' => $f['inicio'], 'fim' => $f['fim'], 'tipo' => $f['tipo'], 'rotulo' => $f['rotulo']];
                if ($f['id'] && DB::table('horario_faixas')->where('id', $f['id'])->where('modelo_id', $id)->exists()) {
                    DB::table('horario_faixas')->where('id', $f['id'])->update($linha);
                } else {
                    DB::table('horario_faixas')->insert($linha);
                }
            }
            // Faixa que virou intervalo não tem aula.
            DB::table('horario_aulas as a')->join('horario_faixas as f', 'f.id', '=', 'a.faixa_id')
                ->where('f.modelo_id', $id)->where('f.tipo', 'intervalo')->delete();
            return $id;
        });
    }

    /** @throws \DomainException */
    public function excluirModelo(int $id): void
    {
        if (DB::table('horario_turmas')->where('modelo_id', $id)->exists()) {
            throw new \DomainException('Há turmas usando este horário. Troque o horário delas antes de excluir.');
        }
        DB::table('horario_modelos')->where('id', $id)->delete();
    }

    /** Primeiro uso: um horário de manhã e um de tarde, prontos para ajustar. */
    public function criarModelosSugeridos(): void
    {
        if (DB::table('horario_modelos')->exists()) {
            return;
        }
        $faixas = fn (array $aulas, array $recreio) => collect($aulas)->map(fn ($a) => ['inicio' => $a[0], 'fim' => $a[1], 'tipo' => 'aula'])
            ->push(['inicio' => $recreio[0], 'fim' => $recreio[1], 'tipo' => 'intervalo', 'rotulo' => 'Recreio'])->all();
        $this->salvarModelo(null, ['nome' => 'Manhã', 'turno' => 'Manhã', 'faixas' => $faixas(
            [['07:00', '07:50'], ['07:50', '08:40'], ['08:40', '09:30'], ['09:50', '10:40'], ['10:40', '11:30']], ['09:30', '09:50'])]);
        $this->salvarModelo(null, ['nome' => 'Tarde', 'turno' => 'Tarde', 'faixas' => $faixas(
            [['13:00', '13:50'], ['13:50', '14:40'], ['14:40', '15:30'], ['15:50', '16:40'], ['16:40', '17:30']], ['15:30', '15:50'])]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Grade da turma
    // ═════════════════════════════════════════════════════════════════════

    /** Turmas ativas, por ano (as do ano mais recente primeiro), com indicação de grade montada. */
    public function turmas(?array $somenteIds = null): array
    {
        $comGrade = DB::table('horario_aulas')->selectRaw('turma_id, COUNT(*) as n')->groupBy('turma_id')->pluck('n', 'turma_id');
        return DB::table('turmas')->where('status', 'Ativa')
            ->when($somenteIds !== null, fn ($q) => $q->whereIn('id', $somenteIds ?: [0]))
            ->orderByDesc('ano_letivo')->orderBy('nome_turma')->get(['id', 'nome_turma', 'turno', 'ano_letivo'])
            ->map(fn ($t) => [
                'id' => (int) $t->id, 'nome' => $t->nome_turma, 'turno' => $t->turno, 'ano_letivo' => (int) $t->ano_letivo,
                'aulas' => (int) ($comGrade[$t->id] ?? 0),
            ])->all();
    }

    public function grade(int $turmaId): ?array
    {
        $turma = DB::table('turmas')->where('id', $turmaId)->first(['id', 'nome_turma', 'turno', 'ano_letivo', 'status']);
        if (!$turma) {
            return null;
        }
        $config = DB::table('horario_turmas')->where('turma_id', $turmaId)->first();
        $modelo = $config ? collect($this->modelos())->firstWhere('id', (int) $config->modelo_id) : null;
        $faixas = collect($modelo['faixas'] ?? [])->keyBy('id');

        $vinculos = $this->vinculos($turmaId);
        $daEscola = $this->aulasComHorario((int) $turma->ano_letivo);
        $aulas = DB::table('horario_aulas as a')->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
            ->where('a.turma_id', $turmaId)->get(['a.id', 'a.dia_semana', 'a.faixa_id', 'a.disciplina_id', 'a.professor_id', 'a.sala', 'd.nome_disciplina'])
            ->map(function ($a) use ($vinculos, $faixas, $turmaId, $daEscola) {
                $profId = $a->professor_id ? (int) $a->professor_id : ($vinculos[$a->disciplina_id]['professor_id'] ?? null);
                $f = $faixas[$a->faixa_id] ?? null;
                return [
                    'id' => (int) $a->id, 'dia' => (int) $a->dia_semana, 'faixa_id' => (int) $a->faixa_id,
                    'disciplina_id' => (int) $a->disciplina_id, 'disciplina' => $a->nome_disciplina,
                    'professor_id' => $profId, 'professor' => $profId ? $this->nomeProfessor($profId) : null,
                    'professor_proprio' => (bool) $a->professor_id, 'sala' => $a->sala,
                    'conflito' => $f && $profId ? $this->conflito($daEscola, $profId, (int) $a->dia_semana, $f['inicio'], $f['fim'], $turmaId) : null,
                ];
            })->values();

        $usadas = $aulas->countBy('disciplina_id');
        return [
            'turma' => ['id' => (int) $turma->id, 'nome' => $turma->nome_turma, 'turno' => $turma->turno, 'ano_letivo' => (int) $turma->ano_letivo,
                'editavel' => $turma->status === 'Ativa'],
            'modelo' => $modelo,
            'dias' => $config ? array_map('intval', explode(',', $config->dias)) : [1, 2, 3, 4, 5],
            'modelo_sugerido' => $modelo ? null : $this->modeloDoTurno($turma->turno),
            'atualizado_em' => $config->atualizado_em ?? null,
            'avisado_em' => $config->avisado_em ?? null,
            'aulas' => $aulas->all(),
            'disciplinas' => collect($vinculos)->map(fn ($v) => $v + ['usadas' => (int) ($usadas[$v['disciplina_id']] ?? 0)])->values()->all(),
        ];
    }

    /**
     * Escolhe o horário (modelo) e os dias da turma. Trocar de modelo mantém a
     * grade: a 1ª aula continua na 1ª aula, a 2ª na 2ª... (o que não cabe sai).
     * @throws \DomainException
     */
    public function configurar(int $turmaId, int $modeloId, array $dias, int $usuarioId): void
    {
        $this->editavel($turmaId);
        if (!DB::table('horario_modelos')->where('id', $modeloId)->exists()) {
            throw new \DomainException('Escolha um horário.');
        }
        $dias = array_values(array_unique(array_filter(array_map('intval', $dias), fn ($d) => isset(self::DIAS[$d]))));
        sort($dias);
        if (!$dias) {
            throw new \DomainException('Escolha ao menos um dia da semana.');
        }

        DB::transaction(function () use ($turmaId, $modeloId, $dias, $usuarioId) {
            $atual = DB::table('horario_turmas')->where('turma_id', $turmaId)->value('modelo_id');
            if ($atual && (int) $atual !== $modeloId) {
                $aulasNovas = DB::table('horario_faixas')->where('modelo_id', $modeloId)->where('tipo', 'aula')->orderBy('ordem')->pluck('id')->values();
                $posicao = DB::table('horario_faixas')->where('modelo_id', $atual)->where('tipo', 'aula')->orderBy('ordem')->pluck('id')->flip();
                foreach (DB::table('horario_aulas')->where('turma_id', $turmaId)->orderByDesc('id')->get(['id', 'faixa_id']) as $a) {
                    $nova = $aulasNovas[$posicao[$a->faixa_id] ?? -1] ?? null;
                    $nova ? DB::table('horario_aulas')->where('id', $a->id)->update(['faixa_id' => $nova])
                        : DB::table('horario_aulas')->where('id', $a->id)->delete();
                }
            }
            DB::table('horario_aulas')->where('turma_id', $turmaId)->whereNotIn('dia_semana', $dias)->delete();
            DB::table('horario_turmas')->updateOrInsert(['turma_id' => $turmaId], [
                'modelo_id' => $modeloId, 'dias' => implode(',', $dias), 'atualizado_em' => now(), 'atualizado_por' => $usuarioId,
            ]);
        });
    }

    /**
     * Põe (ou troca) a aula de um dia × horário.
     * @throws \DomainException
     */
    public function salvarAula(int $turmaId, int $dia, int $faixaId, int $disciplinaId, ?int $professorId, ?string $sala, int $usuarioId): array
    {
        $this->validarCasa($turmaId, $dia, $faixaId);
        if (!DB::table('disciplinas')->where('id', $disciplinaId)->exists()) {
            throw new \DomainException('Disciplina não encontrada.');
        }
        // Professor igual ao do vínculo não precisa ficar gravado: se o vínculo mudar, a grade acompanha.
        $doVinculo = DB::table('turma_professor_disciplina')->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)->value('professor_id');
        $professorId = $professorId && (int) $professorId !== (int) $doVinculo ? $professorId : null;

        DB::table('horario_aulas')->updateOrInsert(
            ['turma_id' => $turmaId, 'dia_semana' => $dia, 'faixa_id' => $faixaId],
            ['disciplina_id' => $disciplinaId, 'professor_id' => $professorId, 'sala' => $sala !== null && trim($sala) !== '' ? mb_substr(trim($sala), 0, 40) : null]
        );
        $this->tocar($turmaId, $usuarioId);
        return $this->grade($turmaId);
    }

    public function removerAula(int $turmaId, int $dia, int $faixaId, int $usuarioId): array
    {
        $this->editavel($turmaId);
        DB::table('horario_aulas')->where('turma_id', $turmaId)->where('dia_semana', $dia)->where('faixa_id', $faixaId)->delete();
        $this->tocar($turmaId, $usuarioId);
        return $this->grade($turmaId);
    }

    /** Arrasta a aula de uma casa para outra; se a outra tiver aula, as duas trocam de lugar. @throws \DomainException */
    public function mover(int $turmaId, array $de, array $para, int $usuarioId): array
    {
        $this->validarCasa($turmaId, (int) $para['dia'], (int) $para['faixa_id']);
        DB::transaction(fn () => $this->trocarCasas($turmaId, $de, $para));
        $this->tocar($turmaId, $usuarioId);
        return $this->grade($turmaId);
    }

    /**
     * Aplica uma sequência de trocas (sugestão do Max ou o "desfazer" dela).
     * Cada troca confere que as aulas ainda estão onde estavam quando a
     * sugestão foi feita; se o quadro mudou, nada é gravado. Fora o desfazer,
     * o total de choques do ano não pode aumentar. Devolve as trocas que
     * desfazem esta.
     *
     * @param array<int, array{turma_id: int, de: array, para: array, origem_id: int, destino_id: ?int}> $ops
     * @throws \DomainException
     */
    public function aplicarTrocas(array $ops, int $usuarioId, bool $desfazer = false): array
    {
        if (!$ops) {
            throw new \DomainException('Nada para aplicar.');
        }
        $turmas = array_values(array_unique(array_map(fn ($o) => (int) $o['turma_id'], $ops)));
        $ano = (int) DB::table('turmas')->where('id', $turmas[0])->value('ano_letivo');
        DB::transaction(function () use ($ops, $ano, $desfazer) {
            $antes = $desfazer ? 0 : count(app(ResolvedorConflitos::class)->conflitosDoAno($ano));
            foreach ($ops as $op) {
                $this->validarCasa((int) $op['turma_id'], (int) $op['para']['dia'], (int) $op['para']['faixa_id']);
                $this->trocarCasas((int) $op['turma_id'], $op['de'], $op['para'], (int) $op['origem_id'], isset($op['destino_id']) ? (int) $op['destino_id'] : null);
            }
            if (!$desfazer && count(app(ResolvedorConflitos::class)->conflitosDoAno($ano)) > $antes) {
                throw new \DomainException('O quadro mudou enquanto o Max pensava. Peça uma nova sugestão.');
            }
        });
        foreach ($turmas as $t) {
            $this->tocar($t, $usuarioId);
        }
        return array_map(fn ($o) => ['turma_id' => (int) $o['turma_id'], 'de' => $o['para'], 'para' => $o['de'],
            'origem_id' => (int) $o['origem_id'], 'destino_id' => isset($o['destino_id']) ? (int) $o['destino_id'] : null], array_reverse($ops));
    }

    /**
     * Leva a aula de uma casa para outra, trocando com a que estiver lá. Com
     * $origemId informado, confere que é essa aula que está na origem (e
     * $destinoId, a do destino) — senão o quadro mudou.
     * @throws \DomainException
     */
    private function trocarCasas(int $turmaId, array $de, array $para, ?int $origemId = null, ?int $destinoId = null): void
    {
        $q = fn ($c) => DB::table('horario_aulas')->where('turma_id', $turmaId)->where('dia_semana', (int) $c['dia'])->where('faixa_id', (int) $c['faixa_id']);
        $origem = $q($de)->lockForUpdate()->first();
        if (!$origem) {
            throw new \DomainException('A aula não está mais nesse horário. Atualize a tela.');
        }
        $destino = $q($para)->lockForUpdate()->first();
        if ($origemId !== null && ((int) $origem->id !== $origemId || ($destino ? (int) $destino->id : null) !== $destinoId)) {
            throw new \DomainException('O quadro mudou enquanto o Max pensava. Peça uma nova sugestão.');
        }
        // Troca sem violar o UNIQUE (turma, dia, faixa): a origem sai do caminho primeiro.
        DB::table('horario_aulas')->where('id', $origem->id)->update(['dia_semana' => 0]);
        if ($destino) {
            DB::table('horario_aulas')->where('id', $destino->id)->update(['dia_semana' => (int) $de['dia'], 'faixa_id' => (int) $de['faixa_id']]);
        }
        DB::table('horario_aulas')->where('id', $origem->id)->update(['dia_semana' => (int) $para['dia'], 'faixa_id' => (int) $para['faixa_id']]);
    }

    /**
     * Estado do quadro de um ano letivo, para o resolvedor de choques: as
     * aulas (com horário real e professor efetivo) e, por turma, as casas de
     * aula disponíveis (dia × faixa).
     */
    public function estadoDoAno(int $ano): array
    {
        $casas = [];
        $linhas = DB::table('horario_turmas as ht')->join('turmas as t', 't.id', '=', 'ht.turma_id')
            ->join('horario_faixas as f', 'f.modelo_id', '=', 'ht.modelo_id')
            ->where('t.status', 'Ativa')->where('t.ano_letivo', $ano)->where('f.tipo', 'aula')
            ->orderBy('f.ordem')->get(['ht.turma_id', 'ht.dias', 'f.id', 'f.ordem', 'f.inicio', 'f.fim']);
        foreach ($linhas as $l) {
            foreach (array_map('intval', explode(',', $l->dias)) as $dia) {
                $casas[(int) $l->turma_id][] = ['dia' => $dia, 'faixa_id' => (int) $l->id, 'ordem' => (int) $l->ordem, 'inicio' => $l->inicio, 'fim' => $l->fim];
            }
        }
        return ['aulas' => $this->aulasComHorario($ano)->keyBy('id')->all(), 'casas' => $casas];
    }

    /** Meta de aulas por semana da disciplina na turma (carga do vínculo professor × disciplina). */
    public function definirCarga(int $turmaId, int $disciplinaId, int $carga): array
    {
        $this->editavel($turmaId);
        DB::table('turma_professor_disciplina')->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)
            ->update(['carga_horaria_semanal' => max(0, min(40, $carga))]);
        return $this->grade($turmaId);
    }

    /** Avisa no sino (e push) as famílias e os alunos da turma que o horário mudou. */
    public function avisar(int $turmaId): int
    {
        $turma = DB::table('turmas')->where('id', $turmaId)->value('nome_turma');
        $alunos = DB::table('turma_aluno')->where('turma_id', $turmaId)->pluck('aluno_id')->all();
        $usuarios = DB::table('aluno_responsavel as ar')->join('responsaveis as r', 'r.id', '=', 'ar.responsavel_id')
            ->whereIn('ar.aluno_id', $alunos ?: [0])->pluck('r.usuario_id')
            ->merge(DB::table('alunos')->whereIn('id', $alunos ?: [0])->pluck('usuario_id'))
            ->unique()->values()->all();
        if ($usuarios && $this->notificacoes->disponivel()) {
            $this->notificacoes->gravar($usuarios, 'horario', "horario:$turmaId", null, "Horário de aulas — $turma",
                'O quadro de horários da turma foi atualizado. Toque para ver a semana.', '/horarios', ['turma' => $turmaId]);
        }
        DB::table('horario_turmas')->where('turma_id', $turmaId)->update(['avisado_em' => now()]);
        return count($usuarios);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Leituras: professor, escola agora, hoje
    // ═════════════════════════════════════════════════════════════════════

    public function professores(): array
    {
        return DB::table('professores as p')->join('usuarios as u', 'u.id', '=', 'p.usuario_id')->where('u.ativo', 1)
            ->orderBy('u.nome_completo')->get(['p.id', 'u.nome_completo as nome'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'nome' => $p->nome])->all();
    }

    /** Semana de um professor em todas as turmas ativas (horários reais, porque turmas podem ter modelos diferentes). */
    public function agendaProfessor(int $professorId): array
    {
        return [
            'professor' => ['id' => $professorId, 'nome' => $this->nomeProfessor($professorId)],
            'aulas' => $this->aulasComHorario($this->anoAtual())->where('professor_id', $professorId)->values()->all(),
        ];
    }

    /** Escola agora: em cada turma, a aula acontecendo (com o andamento) e a próxima. */
    public function agora(?Carbon $momento = null): array
    {
        $m = $momento ?? now();
        $dia = $m->dayOfWeekIso;
        $hora = $m->format('H:i:s');
        $aulas = $this->aulasComHorario($this->anoAtual())->where('dia', $dia);
        $intervalos = DB::table('horario_turmas as ht')->join('horario_faixas as f', 'f.modelo_id', '=', 'ht.modelo_id')
            ->where('f.tipo', 'intervalo')->where('f.inicio', '<=', $hora)->where('f.fim', '>', $hora)
            ->get(['ht.turma_id', 'ht.dias', 'f.inicio', 'f.fim', 'f.rotulo'])
            ->filter(fn ($i) => in_array($dia, array_map('intval', explode(',', $i->dias)), true))->keyBy('turma_id');

        return collect($this->turmas())->where('ano_letivo', $this->anoAtual())->map(function ($t) use ($aulas, $hora, $intervalos, $m) {
            $daTurma = $aulas->where('turma_id', $t['id'])->sortBy('inicio')->values();
            $atual = $daTurma->first(fn ($a) => $a['inicio'] <= $hora && $a['fim'] > $hora);
            $proxima = $daTurma->first(fn ($a) => $a['inicio'] > $hora);
            $intervalo = $intervalos[$t['id']] ?? null;
            return $t + [
                'atual' => $atual ? $atual + ['progresso' => $this->progresso($atual['inicio'], $atual['fim'], $m)] : null,
                'intervalo' => $intervalo ? ['rotulo' => $intervalo->rotulo ?: 'Intervalo', 'inicio' => $this->hm($intervalo->inicio), 'fim' => $this->hm($intervalo->fim)] : null,
                'proxima' => $proxima,
                'aulas_hoje' => $daTurma->count(),
            ];
        })->filter(fn ($t) => $t['aulas_hoje'] > 0)->values()->all();
    }

    /**
     * Hoje do usuário: para a família, a aula atual e a próxima de cada filho;
     * para o professor, as aulas dele hoje.
     */
    public function hoje(int $usuarioId, bool $professor, bool $familia): array
    {
        $m = now();
        $hora = $m->format('H:i:s');
        $aulas = $this->aulasComHorario($this->anoAtual())->where('dia', $m->dayOfWeekIso)->sortBy('inicio')->values();
        $resumo = fn ($lista) => [
            'aulas' => $lista->values()->all(),
            'atual' => ($a = $lista->first(fn ($x) => $x['inicio'] <= $hora && $x['fim'] > $hora)) ? $a + ['progresso' => $this->progresso($a['inicio'], $a['fim'], $m)] : null,
            'proxima' => $lista->first(fn ($x) => $x['inicio'] > $hora),
        ];

        $out = ['dia' => self::DIAS[$m->dayOfWeekIso] ?? null, 'agora' => $m->format('H:i'), 'professor' => null, 'dependentes' => []];
        if ($professor) {
            $profId = (int) DB::table('professores')->where('usuario_id', $usuarioId)->value('id');
            $out['professor'] = $profId ? $resumo($aulas->where('professor_id', $profId)) : null;
        }
        if ($familia) {
            foreach ($this->dependentes($usuarioId) as $d) {
                $out['dependentes'][] = ['aluno_id' => $d->aluno_id, 'nome' => $d->nome, 'turma_id' => (int) $d->turma_id, 'turma' => $d->turma]
                    + $resumo($aulas->where('turma_id', (int) $d->turma_id));
            }
        }
        return $out;
    }

    /** Turmas que o usuário pode ver: equipe vê todas; família e aluno, as dos filhos / a sua. */
    public function turmasDoUsuario(int $usuarioId, bool $equipe): ?array
    {
        if ($equipe) {
            return null;
        }
        $proprias = DB::table('alunos as a')->join('turma_aluno as ta', 'ta.aluno_id', '=', 'a.id')->where('a.usuario_id', $usuarioId)->pluck('ta.turma_id');
        return collect($this->dependentes($usuarioId))->pluck('turma_id')->merge($proprias)->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Agenda do celular (.ics)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Arquivo de calendário com as aulas da turma, toda semana até o fim do
     * ano letivo — sem os feriados e recessos do calendário escolar.
     */
    public function ics(int $turmaId): ?string
    {
        $g = $this->grade($turmaId);
        if (!$g || !$g['modelo']) {
            return null;
        }
        $ano = $g['turma']['ano_letivo'];
        $unidades = app(PeriodoLetivoService::class)->unidadesComDatas($ano);
        $inicioAno = Carbon::parse($unidades->min('data_inicio') ?: "$ano-02-01");
        $fimAno = Carbon::parse($unidades->max('data_fim') ?: "$ano-12-15");
        $de = $inicioAno->max(now()->startOfDay());
        $calendario = app(CalendarioEscolarService::class);
        $mapa = $calendario->mapa($ano);
        $faixas = collect($g['modelo']['faixas'])->keyBy('id');
        $escola = app(\App\Services\Documentos\EscolaConfigService::class)->obter()['nome_escola'] ?? 'Escola';

        $linhas = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Master School//Quadro de Horarios//PT', 'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:' . $this->icsTexto("Aulas — {$g['turma']['nome']}"), 'X-WR-TIMEZONE:America/Sao_Paulo'];
        foreach ($g['aulas'] as $a) {
            $f = $faixas[$a['faixa_id']] ?? null;
            if (!$f) {
                continue;
            }
            $primeiro = $de->copy();
            while ($primeiro->dayOfWeekIso !== $a['dia']) {
                $primeiro->addDay();
            }
            if ($primeiro->gt($fimAno)) {
                continue;
            }
            // Dias sem aula (feriado, recesso) dessa semana-dia ficam de fora.
            $excecoes = [];
            for ($d = $primeiro->copy(); $d->lte($fimAno); $d->addWeek()) {
                if (!$calendario->ehLetivo($d->toDateString(), $mapa)) {
                    $excecoes[] = 'EXDATE;TZID=America/Sao_Paulo:' . $d->format('Ymd') . 'T' . str_replace(':', '', $f['inicio']) . '00';
                }
            }
            $linhas = array_merge($linhas, [
                'BEGIN:VEVENT',
                'UID:horario-' . $turmaId . '-' . $a['id'] . '@masterschool',
                'DTSTAMP:' . now()->utc()->format('Ymd\THis\Z'),
                'DTSTART;TZID=America/Sao_Paulo:' . $primeiro->format('Ymd') . 'T' . str_replace(':', '', $f['inicio']) . '00',
                'DTEND;TZID=America/Sao_Paulo:' . $primeiro->format('Ymd') . 'T' . str_replace(':', '', $f['fim']) . '00',
                'RRULE:FREQ=WEEKLY;UNTIL=' . $fimAno->format('Ymd') . 'T235959Z',
                'SUMMARY:' . $this->icsTexto($a['disciplina']),
                'DESCRIPTION:' . $this->icsTexto(trim(($a['professor'] ? "Prof. {$a['professor']}" : '') . ($a['sala'] ? " · Sala {$a['sala']}" : '') . " · {$g['turma']['nome']}", ' ·')),
                'LOCATION:' . $this->icsTexto($a['sala'] ? "$escola — Sala {$a['sala']}" : $escola),
            ], $excecoes, ['END:VEVENT']);
        }
        $linhas[] = 'END:VCALENDAR';
        return implode("\r\n", $linhas) . "\r\n";
    }

    // ═════════════════════════════════════════════════════════════════════
    // Apoio
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Ano letivo em andamento: "agora", "hoje" e a agenda do professor olham só para ele
     * (as turmas do ano seguinte, montadas na virada, ainda não têm aula).
     */
    private function anoAtual(): int
    {
        return (int) now()->year;
    }

    /** Aulas das turmas ativas (de um ano letivo, se informado) com o horário real (início/fim) e o professor efetivo. */
    private function aulasComHorario(?int $ano = null)
    {
        $vinculos = DB::table('turma_professor_disciplina')->get(['turma_id', 'disciplina_id', 'professor_id'])
            ->mapWithKeys(fn ($v) => [$v->turma_id . ':' . $v->disciplina_id => $v->professor_id ? (int) $v->professor_id : null]);
        return DB::table('horario_aulas as a')
            ->join('horario_turmas as ht', 'ht.turma_id', '=', 'a.turma_id')
            ->join('horario_faixas as f', 'f.id', '=', 'a.faixa_id')
            ->join('turmas as t', 't.id', '=', 'a.turma_id')->join('disciplinas as d', 'd.id', '=', 'a.disciplina_id')
            ->where('t.status', 'Ativa')->when($ano, fn ($q) => $q->where('t.ano_letivo', $ano))
            ->get(['a.id', 'a.turma_id', 't.nome_turma', 'a.dia_semana', 'a.faixa_id', 'f.inicio', 'f.fim', 'a.disciplina_id', 'd.nome_disciplina',
                'a.professor_id', 'a.sala', 'ht.dias'])
            ->filter(fn ($a) => in_array((int) $a->dia_semana, array_map('intval', explode(',', $a->dias)), true))
            ->map(function ($a) use ($vinculos) {
                $prof = $a->professor_id ? (int) $a->professor_id : ($vinculos[$a->turma_id . ':' . $a->disciplina_id] ?? null);
                return [
                    'id' => (int) $a->id, 'turma_id' => (int) $a->turma_id, 'turma' => $a->nome_turma, 'dia' => (int) $a->dia_semana,
                    'faixa_id' => (int) $a->faixa_id, 'inicio' => $a->inicio, 'fim' => $a->fim,
                    'disciplina_id' => (int) $a->disciplina_id, 'disciplina' => $a->nome_disciplina,
                    'professor_id' => $prof, 'professor' => $prof ? $this->nomeProfessor($prof) : null, 'sala' => $a->sala,
                ];
            })->values();
    }

    /** Disciplinas da turma (vínculos), com o professor e a carga semanal. */
    private function vinculos(int $turmaId): array
    {
        return DB::table('turma_professor_disciplina as v')->join('disciplinas as d', 'd.id', '=', 'v.disciplina_id')
            ->where('v.turma_id', $turmaId)->orderBy('d.nome_disciplina')
            ->get(['v.disciplina_id', 'd.nome_disciplina', 'v.professor_id', 'v.carga_horaria_semanal'])
            ->mapWithKeys(fn ($v) => [(int) $v->disciplina_id => [
                'disciplina_id' => (int) $v->disciplina_id, 'nome' => $v->nome_disciplina,
                'professor_id' => $v->professor_id ? (int) $v->professor_id : null,
                'professor' => $v->professor_id ? $this->nomeProfessor((int) $v->professor_id) : null,
                'carga' => (int) $v->carga_horaria_semanal,
            ]])->all();
    }

    /** O professor já está em outra turma nesse dia e horário? Devolve a turma (ou null). */
    private function conflito($daEscola, int $professorId, int $dia, string $inicio, string $fim, int $turmaId): ?string
    {
        return $daEscola->first(fn ($a) => $a['professor_id'] === $professorId && $a['dia'] === $dia
            && $a['turma_id'] !== $turmaId && $a['inicio'] < $fim . ':00' && $a['fim'] > $inicio . ':00')['turma'] ?? null;
    }

    private array $nomes = [];

    private function nomeProfessor(int $professorId): ?string
    {
        return $this->nomes[$professorId] ??= DB::table('professores as p')->join('usuarios as u', 'u.id', '=', 'p.usuario_id')
            ->where('p.id', $professorId)->value('u.nome_completo');
    }

    private function dependentes(int $usuarioId)
    {
        return DB::table('responsaveis as r')->join('aluno_responsavel as ar', 'ar.responsavel_id', '=', 'r.id')
            ->join('alunos as a', 'a.id', '=', 'ar.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->join('turma_aluno as ta', 'ta.aluno_id', '=', 'a.id')->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->where('r.usuario_id', $usuarioId)->where('u.ativo', 1)->where('t.status', 'Ativa')
            ->orderBy('u.nome_completo')->get(['a.id as aluno_id', 'u.nome_completo as nome', 't.id as turma_id', 't.nome_turma as turma'])
            ->unique('aluno_id')->values();
    }

    private function modeloDoTurno(?string $turno): ?int
    {
        $id = DB::table('horario_modelos')->where('turno', $turno)->orderBy('id')->value('id');
        return $id ? (int) $id : null;
    }

    /** @throws \DomainException */
    private function editavel(int $turmaId): void
    {
        $status = DB::table('turmas')->where('id', $turmaId)->value('status');
        if (!$status) {
            throw new \DomainException('Turma não encontrada.');
        }
        if ($status !== 'Ativa') {
            throw new \DomainException('Turma encerrada: o horário dela fica só para consulta.');
        }
    }

    /** @throws \DomainException */
    private function validarCasa(int $turmaId, int $dia, int $faixaId): void
    {
        $this->editavel($turmaId);
        $config = DB::table('horario_turmas')->where('turma_id', $turmaId)->first();
        if (!$config) {
            throw new \DomainException('Escolha primeiro o horário da turma (manhã, tarde...).');
        }
        if (!in_array($dia, array_map('intval', explode(',', $config->dias)), true)) {
            throw new \DomainException('Este dia não faz parte da semana da turma.');
        }
        $faixa = DB::table('horario_faixas')->where('id', $faixaId)->where('modelo_id', $config->modelo_id)->first(['tipo']);
        if (!$faixa || $faixa->tipo !== 'aula') {
            throw new \DomainException('Escolha um horário de aula (intervalo não tem aula).');
        }
    }

    private function tocar(int $turmaId, int $usuarioId): void
    {
        DB::table('horario_turmas')->where('turma_id', $turmaId)->update(['atualizado_em' => now(), 'atualizado_por' => $usuarioId]);
    }

    private function progresso(string $inicio, string $fim, Carbon $m): int
    {
        $i = Carbon::parse($m->toDateString() . ' ' . $inicio);
        $f = Carbon::parse($m->toDateString() . ' ' . $fim);
        $total = max(1, $f->getTimestamp() - $i->getTimestamp());
        return (int) max(0, min(100, round(($m->getTimestamp() - $i->getTimestamp()) * 100 / $total)));
    }

    private function faixa(object $f): array
    {
        return ['id' => (int) $f->id, 'ordem' => (int) $f->ordem, 'inicio' => $this->hm($f->inicio), 'fim' => $this->hm($f->fim),
            'tipo' => $f->tipo, 'rotulo' => $f->rotulo];
    }

    /** "7:5" / "07:05" / "07:05:00" → "07:05:00" (ou '' se inválido). */
    private function hora(string $h): string
    {
        return preg_match('/^(\d{1,2}):(\d{1,2})/', trim($h), $m) && (int) $m[1] < 24 && (int) $m[2] < 60
            ? sprintf('%02d:%02d:00', $m[1], $m[2]) : '';
    }

    private function hm(string $h): string
    {
        return substr($h, 0, 5);
    }

    private function icsTexto(string $t): string
    {
        return str_replace(["\\", ';', ',', "\n"], ["\\\\", '\\;', '\\,', '\\n'], $t);
    }
}
