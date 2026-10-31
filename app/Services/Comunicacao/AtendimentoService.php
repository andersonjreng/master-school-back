<?php

namespace App\Services\Comunicacao;

use App\Services\Notificacoes\NotificacaoService;
use App\Support\Papeis;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atendimentos: a família marca pelo app uma conversa com a escola.
 *
 * Quem atende (equipe) abre horários (janelas): um dia, das X às Y, de N em N
 * minutos, presencial e/ou online. A família vê, por filho, quem pode
 * atendê-la — os professores do filho e a gestão (coordenação, direção,
 * secretaria) que abriu horários — e marca um horário livre com o assunto.
 * Os dois lados recebem aviso (sino + celular) ao marcar e ao cancelar.
 */
class AtendimentoService
{
    /** Antecedência mínima para marcar (o atendente precisa saber antes). */
    private const ANTECEDENCIA_MIN = 120;
    private const DIAS_A_FRENTE = 45;

    public const ROTULOS_PAPEL = [
        Papeis::DIRETORIA => 'Direção', Papeis::COORDENACAO => 'Coordenação', Papeis::SECRETARIA => 'Secretaria',
        Papeis::ADMINISTRADOR => 'Direção', Papeis::TESOURARIA => 'Financeiro',
    ];

    public function __construct(private NotificacaoService $notificacoes)
    {
    }

    public function disponivel(): bool
    {
        return Schema::hasTable('atendimentos');
    }

    // ── Quem atende: horários ───────────────────────────────────────────

    /** Horários futuros (e de hoje) de quem atende, com cada intervalo e se está ocupado. */
    public function janelas(int $usuarioId): array
    {
        $janelas = DB::table('atendimento_janelas')->where('usuario_id', $usuarioId)->where('data', '>=', now()->toDateString())
            ->orderBy('data')->orderBy('inicio')->get();
        $marcados = $this->marcadosDasJanelas($janelas->pluck('id')->all());
        return $janelas->map(fn ($j) => $this->formatarJanela($j, $marcados))->values()->all();
    }

    /**
     * Abre horários em um ou mais dias (mesmo intervalo). Não deixa sobrepor
     * outro horário da mesma pessoa no mesmo dia.
     *
     * @param array{datas: string[], inicio: string, fim: string, duracao: int, modo: string, local: ?string} $d
     */
    public function abrir(int $usuarioId, array $d): array
    {
        $datas = array_values(array_unique(array_filter((array) ($d['datas'] ?? []), fn ($x) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x))));
        $inicio = $this->hora($d['inicio'] ?? null);
        $fim = $this->hora($d['fim'] ?? null);
        $duracao = (int) ($d['duracao'] ?? 20);
        $modo = in_array($d['modo'] ?? '', ['presencial', 'online', 'ambos'], true) ? $d['modo'] : 'presencial';
        $local = mb_substr(trim((string) ($d['local'] ?? '')), 0, 200) ?: null;

        $erro = match (true) {
            !$datas => 'Escolha pelo menos um dia.',
            count($datas) > 30 => 'No máximo 30 dias de uma vez.',
            !$inicio || !$fim || $fim <= $inicio => 'O horário de término precisa ser depois do início.',
            $duracao < 10 || $duracao > 120 => 'Cada atendimento deve ter de 10 a 120 minutos.',
            (strtotime("2000-01-01 $fim") - strtotime("2000-01-01 $inicio")) / 60 < $duracao => 'O intervalo é menor que um atendimento.',
            min($datas) < now()->toDateString() => 'Não dá para abrir horários em dias que já passaram.',
            max($datas) > now()->addDays(120)->toDateString() => 'Abra horários para no máximo 4 meses à frente.',
            default => null,
        };
        if ($erro) {
            throw new \DomainException($erro);
        }
        $choque = DB::table('atendimento_janelas')->where('usuario_id', $usuarioId)->whereIn('data', $datas)
            ->where('inicio', '<', $fim)->where('fim', '>', $inicio)->orderBy('data')->first(['data']);
        if ($choque) {
            throw new \DomainException('Você já tem horário aberto que se sobrepõe em ' . Carbon::parse($choque->data)->format('d/m') . '.');
        }
        foreach ($datas as $data) {
            DB::table('atendimento_janelas')->insert([
                'usuario_id' => $usuarioId, 'data' => $data, 'inicio' => $inicio, 'fim' => $fim,
                'duracao' => $duracao, 'modo' => $modo, 'local' => $local,
            ]);
        }
        return $this->janelas($usuarioId);
    }

    /** Fecha um horário. Atendimentos já marcados nele são cancelados (com aviso à família). */
    public function fecharJanela(int $usuarioId, int $janelaId): array
    {
        $j = DB::table('atendimento_janelas')->where('id', $janelaId)->where('usuario_id', $usuarioId)->first();
        if (!$j) {
            throw new \DomainException('Horário não encontrado.');
        }
        foreach (DB::table('atendimentos')->where('janela_id', $janelaId)->where('status', 'marcado')->pluck('id') as $id) {
            $this->cancelarComo((int) $id, $usuarioId, 'O horário foi fechado pela escola.');
        }
        DB::table('atendimento_janelas')->where('id', $janelaId)->delete();
        return $this->janelas($usuarioId);
    }

    // ── Família: com quem e quando ─────────────────────────────────────

    /**
     * Por filho: quem pode atender (com os próximos horários livres de cada um).
     * Professores do filho aparecem mesmo sem horário (a família vê que ainda não há).
     */
    public function opcoesFamilia(PerfilComunicacao $p): array
    {
        if (!$p->ehFamilia()) {
            return [];
        }
        $gestao = $this->atendentesDaGestao();
        return DB::table('alunos as a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')->whereIn('a.id', $p->dependentes ?: [0])
            ->where('u.ativo', 1)->orderBy('u.nome_completo')->get(['a.id', 'u.nome_completo'])
            ->map(function ($a) use ($gestao) {
                $pessoas = array_merge($gestao, $this->professoresDoAluno((int) $a->id));
                $vistos = [];
                $lista = [];
                foreach ($pessoas as $x) {
                    if (isset($vistos[$x['usuario_id']])) {
                        continue;
                    }
                    $vistos[$x['usuario_id']] = true;
                    $livres = $this->livres($x['usuario_id']);
                    $lista[] = $x + ['livres' => count($livres), 'proximo' => $livres[0]['inicio'] ?? null];
                }
                // Quem tem horário primeiro; depois a ordem natural (gestão, professores).
                usort($lista, fn ($p1, $p2) => [$p2['livres'] > 0] <=> [$p1['livres'] > 0]);
                return ['aluno_id' => (int) $a->id, 'aluno' => $a->nome_completo, 'atendentes' => $lista];
            })->values()->all();
    }

    /** Horários livres de quem atende, nos próximos dias, agrupados por dia. */
    public function livresPorDia(int $atendenteId): array
    {
        $dias = [];
        foreach ($this->livres($atendenteId) as $s) {
            $dia = substr($s['inicio'], 0, 10);
            $dias[$dia] ??= ['data' => $dia, 'horarios' => []];
            $dias[$dia]['horarios'][] = $s;
        }
        return array_values($dias);
    }

    public function marcar(PerfilComunicacao $p, array $d): array
    {
        $alunoId = (int) ($d['aluno_id'] ?? 0);
        $atendente = (int) ($d['atendente_id'] ?? 0);
        $inicio = (string) ($d['inicio'] ?? '');
        $assunto = mb_substr(trim((string) ($d['assunto'] ?? '')), 0, 300);
        if (!in_array($alunoId, $p->dependentes, true)) {
            throw new \DomainException('Escolha o filho.');
        }
        if ($assunto === '') {
            throw new \DomainException('Conte em poucas palavras o assunto.');
        }
        $opcoes = collect($this->opcoesFamilia($p))->firstWhere('aluno_id', $alunoId);
        if (!collect($opcoes['atendentes'] ?? [])->contains('usuario_id', $atendente)) {
            throw new \DomainException('Essa pessoa não atende este aluno.');
        }

        $id = DB::transaction(function () use ($p, $alunoId, $atendente, $inicio, $assunto, $d) {
            // Trava os horários de quem atende: dois pais não pegam o mesmo intervalo.
            DB::table('atendimento_janelas')->where('usuario_id', $atendente)->lockForUpdate()->get(['id']);
            $slot = collect($this->livres($atendente))->firstWhere('inicio', $inicio);
            if (!$slot) {
                throw new \DomainException('Esse horário acabou de ser ocupado. Escolha outro.');
            }
            $modo = $slot['modo'] === 'ambos' ? (($d['modo'] ?? '') === 'online' ? 'online' : 'presencial') : $slot['modo'];
            $outro = DB::table('atendimentos')->where('aluno_id', $alunoId)->where('atendente_usuario_id', $atendente)
                ->where('status', 'marcado')->where('inicio', '>=', now())->exists();
            if ($outro) {
                throw new \DomainException('Já existe um atendimento marcado com essa pessoa para este aluno.');
            }
            return DB::table('atendimentos')->insertGetId([
                'janela_id' => $slot['janela_id'], 'atendente_usuario_id' => $atendente,
                'inicio' => $slot['inicio'], 'fim' => $slot['fim'], 'aluno_id' => $alunoId, 'familia_usuario_id' => $p->usuarioId,
                'assunto' => $assunto, 'modo' => $modo, 'status' => 'marcado',
            ]);
        });

        $a = $this->buscar($id);
        $quando = $this->quando($a->inicio);
        $this->notificacoes->gravar([$atendente], 'atendimento', "atendimento:$id", $alunoId,
            "Atendimento marcado: $quando", $this->juntar([$a->familia, "sobre {$a->aluno}", $assunto]),
            '/comunicacao', ['aba' => 'atendimentos']);
        return $this->formatar($a, $p->usuarioId);
    }

    // ── Os dois lados ───────────────────────────────────────────────────

    /** Atendimentos da pessoa (como quem atende ou como família): próximos e os últimos 30 dias. */
    public function meus(PerfilComunicacao $p): array
    {
        $rows = DB::table('atendimentos')
            ->where(fn ($q) => $q->where('atendente_usuario_id', $p->usuarioId)->orWhere('familia_usuario_id', $p->usuarioId)
                ->orWhereIn('aluno_id', $p->dependentes ?: [0]))
            ->where('inicio', '>=', now()->subDays(30)->startOfDay())
            ->orderBy('inicio')->pluck('id');
        return $rows->map(fn ($id) => $this->formatar($this->buscar((int) $id), $p->usuarioId, $p))->values()->all();
    }

    public function cancelar(int $id, PerfilComunicacao $p, ?string $motivo): array
    {
        $a = $this->buscar($id);
        if (!$a || !$this->participa($a, $p)) {
            throw new \DomainException('Atendimento não encontrado.');
        }
        $this->cancelarComo($id, $p->usuarioId, $motivo);
        return $this->formatar($this->buscar($id), $p->usuarioId, $p);
    }

    public function concluir(int $id, PerfilComunicacao $p): array
    {
        $a = $this->buscar($id);
        if (!$a || (int) $a->atendente_usuario_id !== $p->usuarioId) {
            throw new \DomainException('Atendimento não encontrado.');
        }
        if ($a->status !== 'marcado') {
            throw new \DomainException('Este atendimento não está marcado.');
        }
        DB::table('atendimentos')->where('id', $id)->update(['status' => 'realizado']);
        return $this->formatar($this->buscar($id), $p->usuarioId, $p);
    }

    // ── Apoio ───────────────────────────────────────────────────────────

    private function cancelarComo(int $id, int $usuarioId, ?string $motivo): void
    {
        $a = $this->buscar($id);
        if ($a->status !== 'marcado') {
            throw new \DomainException('Este atendimento não está mais marcado.');
        }
        $motivo = mb_substr(trim((string) $motivo), 0, 300) ?: null;
        DB::table('atendimentos')->where('id', $id)->update(['status' => 'cancelado', 'cancelado_por' => $usuarioId, 'motivo' => $motivo]);
        // Avisa o outro lado (escola cancelou → família do aluno; família cancelou → quem atende).
        $daEscola = $usuarioId === (int) $a->atendente_usuario_id;
        $para = $daEscola ? $this->usuariosFamilia((int) $a->aluno_id) : [(int) $a->atendente_usuario_id];
        $quem = (string) DB::table('usuarios')->where('id', $usuarioId)->value('nome_completo');
        $this->notificacoes->gravar(array_diff($para, [$usuarioId]), 'atendimento', "atendimento:$id", (int) $a->aluno_id,
            'Atendimento cancelado: ' . $this->quando($a->inicio), $this->juntar([$quem, $motivo]),
            '/comunicacao', ['aba' => 'atendimentos']);
    }

    private function participa(object $a, PerfilComunicacao $p): bool
    {
        return (int) $a->atendente_usuario_id === $p->usuarioId || (int) $a->familia_usuario_id === $p->usuarioId
            || in_array((int) $a->aluno_id, $p->dependentes, true);
    }

    /**
     * Intervalos livres de quem atende (a partir de agora + antecedência, até DIAS_A_FRENTE).
     *
     * @return array<int, array{janela_id:int, inicio:string, fim:string, modo:string, local:?string}>
     */
    private function livres(int $atendenteId): array
    {
        $limite = now()->addMinutes(self::ANTECEDENCIA_MIN)->format('Y-m-d H:i:s');
        $janelas = DB::table('atendimento_janelas')->where('usuario_id', $atendenteId)
            ->whereBetween('data', [now()->toDateString(), now()->addDays(self::DIAS_A_FRENTE)->toDateString()])
            ->orderBy('data')->orderBy('inicio')->get();
        $ocupados = DB::table('atendimentos')->where('atendente_usuario_id', $atendenteId)->where('status', 'marcado')
            ->where('inicio', '>=', now()->toDateString())->pluck('inicio')->map(fn ($x) => substr((string) $x, 0, 16))->flip();
        $out = [];
        foreach ($janelas as $j) {
            foreach ($this->intervalos($j) as [$ini, $fim]) {
                if ($ini >= $limite && !isset($ocupados[substr($ini, 0, 16)])) {
                    $out[] = ['janela_id' => (int) $j->id, 'inicio' => $ini, 'fim' => $fim, 'modo' => $j->modo, 'local' => $j->modo === 'online' ? null : $j->local];
                }
            }
        }
        return $out;
    }

    /** @return array<int, array{0:string, 1:string}> [início, fim] de cada atendimento da janela */
    private function intervalos(object $j): array
    {
        $out = [];
        $t = strtotime("{$j->data} {$j->inicio}");
        $fim = strtotime("{$j->data} {$j->fim}");
        while ($t + $j->duracao * 60 <= $fim) {
            $out[] = [date('Y-m-d H:i:s', $t), date('Y-m-d H:i:s', $t + $j->duracao * 60)];
            $t += $j->duracao * 60;
        }
        return $out;
    }

    private function marcadosDasJanelas(array $ids): array
    {
        return DB::table('atendimentos as a')->join('alunos as al', 'al.id', '=', 'a.aluno_id')->join('usuarios as u', 'u.id', '=', 'al.usuario_id')
            ->whereIn('a.janela_id', $ids ?: [0])->where('a.status', '!=', 'cancelado')
            ->get(['a.janela_id', 'a.inicio', 'a.id', 'a.status', 'u.nome_completo as aluno'])
            ->groupBy('janela_id')->map(fn ($g) => $g->keyBy(fn ($x) => substr((string) $x->inicio, 0, 16)))->all();
    }

    private function formatarJanela(object $j, array $marcados): array
    {
        $doDia = $marcados[$j->id] ?? collect();
        $intervalos = array_map(function ($i) use ($doDia) {
            $m = $doDia[substr($i[0], 0, 16)] ?? null;
            return ['inicio' => $i[0], 'fim' => $i[1], 'atendimento_id' => $m ? (int) $m->id : null, 'aluno' => $m->aluno ?? null];
        }, $this->intervalos($j));
        return [
            'id' => (int) $j->id, 'data' => $j->data, 'inicio' => substr($j->inicio, 0, 5), 'fim' => substr($j->fim, 0, 5),
            'duracao' => (int) $j->duracao, 'modo' => $j->modo, 'local' => $j->local,
            'intervalos' => $intervalos, 'ocupados' => count(array_filter($intervalos, fn ($i) => $i['atendimento_id'])),
        ];
    }

    private function buscar(int $id): ?object
    {
        return DB::table('atendimentos as a')
            ->join('alunos as al', 'al.id', '=', 'a.aluno_id')->join('usuarios as ua', 'ua.id', '=', 'al.usuario_id')
            ->join('usuarios as ut', 'ut.id', '=', 'a.atendente_usuario_id')->join('usuarios as uf', 'uf.id', '=', 'a.familia_usuario_id')
            ->leftJoin('atendimento_janelas as j', 'j.id', '=', 'a.janela_id')
            ->leftJoin('turma_aluno as ta', 'ta.aluno_id', '=', 'a.aluno_id')->leftJoin('turmas as t', 't.id', '=', 'ta.turma_id')
            ->where('a.id', $id)
            ->select('a.*', 'ua.nome_completo as aluno', 'ut.nome_completo as atendente', 'uf.nome_completo as familia', 'uf.telefone as familia_telefone',
                'j.local', 't.nome_turma as turma')
            ->first();
    }

    private function formatar(object $a, int $eu, ?PerfilComunicacao $p = null): array
    {
        $souAtendente = (int) $a->atendente_usuario_id === $eu;
        $futuro = $a->inicio > now()->toDateTimeString();
        return [
            'id' => (int) $a->id,
            'inicio' => $a->inicio,
            'fim' => $a->fim,
            'modo' => $a->modo,
            'local' => $a->local,
            'status' => $a->status,
            'assunto' => $a->assunto,
            'motivo' => $a->motivo,
            'aluno' => ['id' => (int) $a->aluno_id, 'nome' => $a->aluno, 'turma' => $a->turma],
            'atendente' => ['id' => (int) $a->atendente_usuario_id, 'nome' => $a->atendente],
            'familia' => ['id' => (int) $a->familia_usuario_id, 'nome' => $a->familia, 'telefone' => $souAtendente ? $a->familia_telefone : null],
            'sou_atendente' => $souAtendente,
            'pode_cancelar' => $a->status === 'marcado' && $futuro,
            'pode_concluir' => $souAtendente && $a->status === 'marcado' && !$futuro,
        ];
    }

    /** Gestão (coordenação, direção, secretaria) que abriu horário: atende qualquer família. */
    private function atendentesDaGestao(): array
    {
        $comHorario = DB::table('atendimento_janelas')->where('data', '>=', now()->toDateString())->distinct()->pluck('usuario_id');
        if ($comHorario->isEmpty()) {
            return [];
        }
        return DB::table('usuarios as u')->join('usuario_funcao as uf', 'uf.usuario_id', '=', 'u.id')->join('funcoes as f', 'f.id', '=', 'uf.funcao_id')
            ->whereIn('u.id', $comHorario)->where('u.ativo', 1)->whereIn('f.nome_funcao', array_keys(self::ROTULOS_PAPEL))
            ->orderBy('u.nome_completo')->get(['u.id', 'u.nome_completo', 'f.nome_funcao'])
            ->unique('id')
            ->map(fn ($x) => ['usuario_id' => (int) $x->id, 'nome' => $x->nome_completo, 'detalhe' => self::ROTULOS_PAPEL[$x->nome_funcao]])
            ->values()->all();
    }

    private function professoresDoAluno(int $alunoId): array
    {
        return DB::table('turma_aluno as ta')
            ->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->join('turma_professor_disciplina as tpd', 'tpd.turma_id', '=', 'ta.turma_id')
            ->join('professores as pr', 'pr.id', '=', 'tpd.professor_id')->join('usuarios as u', 'u.id', '=', 'pr.usuario_id')
            ->join('disciplinas as d', 'd.id', '=', 'tpd.disciplina_id')
            ->where('ta.aluno_id', $alunoId)->where('u.ativo', 1)->where('t.status', 'Ativa')
            ->groupBy('u.id', 'u.nome_completo')
            ->selectRaw('u.id AS usuario_id, u.nome_completo AS nome, MAX(t.nome_turma) AS turma, COUNT(DISTINCT d.id) AS n,
                GROUP_CONCAT(DISTINCT d.nome_disciplina ORDER BY d.nome_disciplina SEPARATOR ", ") AS disciplinas')
            ->orderBy('u.nome_completo')->get()
            // Professora polivalente (Infantil, anos iniciais): "Professor(a) da turma" em vez da lista toda.
            ->map(fn ($x) => ['usuario_id' => (int) $x->usuario_id, 'nome' => $x->nome,
                'detalhe' => (int) $x->n > 3 ? "Professor(a) da turma {$x->turma}" : $x->disciplinas])->values()->all();
    }

    /** @return int[] */
    private function usuariosFamilia(int $alunoId): array
    {
        return DB::table('aluno_responsavel as ar')->join('responsaveis as r', 'r.id', '=', 'ar.responsavel_id')
            ->where('ar.aluno_id', $alunoId)->pluck('r.usuario_id')->map(fn ($x) => (int) $x)->all();
    }

    private function hora($v): ?string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v) ? "$v:00" : null;
    }

    /** "terça, 04/11 às 14:20" */
    private function quando(string $dt): string
    {
        $d = Carbon::parse($dt);
        $dias = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
        return $dias[$d->dayOfWeek] . ', ' . $d->format('d/m') . ' às ' . $d->format('H:i');
    }

    private function juntar(array $partes): string
    {
        return implode(' · ', array_filter($partes, fn ($x) => $x !== null && $x !== ''));
    }
}
