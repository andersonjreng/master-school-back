<?php

namespace App\Services\Agenda;

use App\Services\Alunos\FotoAlunoService;
use App\Services\Notificacoes\NotificacaoService;
use App\Support\Permissoes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda da Educação Infantil: a rotina do dia de cada criança (lanche,
 * almoço, sono, humor, fralda...) para a família.
 *
 * A professora responde "para a turma toda" e ajusta só quem foi diferente
 * (exceções em agenda_alunos). Valor de cada criança = exceção ?? padrão da
 * turma. A família só vê depois de "Enviar" — a partir daí as correções
 * aparecem na hora, sem novo aviso.
 *
 * Quem preenche: professor da turma ou pedagogico.editar. Quem consulta tudo:
 * pedagogico.ver. Itens da rotina: pedagogico.editar. Família: os filhos que
 * estão em turma de Educação Infantil (turmas.etapa_bncc preenchida).
 */
class AgendaService
{
    public const TONS = ['bom', 'medio', 'ruim'];
    private const MAX_CONTADOR = 20;

    public function __construct(private NotificacaoService $notificacoes)
    {
    }

    public function disponivel(): bool
    {
        return Schema::hasTable('agenda_alunos');
    }

    // ── Acesso ──────────────────────────────────────────────────────────

    /**
     * O que o usuário faz na agenda.
     *
     * @return array{turmas: array, filhos: array, pode_configurar: bool}
     */
    public function acesso(int $usuarioId, array $funcoes): array
    {
        $turmas = $this->turmasInfantis();
        $verTodas = Permissoes::pode($funcoes, Permissoes::PEDAGOGICO_VER);
        $editarTodas = Permissoes::pode($funcoes, Permissoes::PEDAGOGICO_EDITAR);
        $doProfessor = $this->turmasDoProfessor($usuarioId);

        $minhas = [];
        foreach ($turmas as $t) {
            $leciona = in_array($t->id, $doProfessor, true);
            if ($verTodas || $leciona) {
                $minhas[] = ['id' => (int) $t->id, 'nome' => $t->nome_turma, 'etapa' => $t->etapa_bncc, 'alunos' => (int) $t->alunos,
                    'pode_editar' => $editarTodas || $leciona, 'minha' => $leciona];
            }
        }
        // Professor: as turmas dele primeiro.
        usort($minhas, fn ($a, $b) => [$b['minha'], $a['nome']] <=> [$a['minha'], $b['nome']]);

        return [
            'turmas' => $minhas,
            'filhos' => $this->filhosNaInfantil($usuarioId),
            'pode_configurar' => $editarTodas,
        ];
    }

    public function podeEditar(int $usuarioId, array $funcoes, int $turmaId): bool
    {
        return collect($this->acesso($usuarioId, $funcoes)['turmas'])->contains(fn ($t) => $t['id'] === $turmaId && $t['pode_editar']);
    }

    public function podeVer(int $usuarioId, array $funcoes, int $turmaId): bool
    {
        return collect($this->acesso($usuarioId, $funcoes)['turmas'])->contains('id', $turmaId);
    }

    // ── Itens da rotina ─────────────────────────────────────────────────

    /** Itens (cria os sugeridos na primeira vez). $etapa filtra os que valem para a etapa da turma. */
    public function itens(bool $soAtivos = true, ?string $etapa = null): array
    {
        if (!DB::table('agenda_itens')->exists()) {
            $this->criarItensSugeridos();
        }
        return DB::table('agenda_itens')->when($soAtivos, fn ($q) => $q->where('ativo', 1))
            ->orderBy('ordem')->orderBy('id')->get()
            ->map(fn ($i) => $this->formatarItem($i))
            ->filter(fn ($i) => !$etapa || !$i['etapas'] || in_array($etapa, $i['etapas'], true))
            ->values()->all();
    }

    /** Grava a lista inteira de itens (na ordem recebida). Item que sai e já foi usado é desativado. */
    public function salvarItens(array $itens): array
    {
        $usados = [];
        DB::transaction(function () use ($itens, &$usados) {
            foreach (array_values($itens) as $ordem => $i) {
                $nome = mb_substr(trim((string) ($i['nome'] ?? '')), 0, 60);
                if ($nome === '') {
                    throw new \DomainException('Todo item precisa de um nome.');
                }
                $tipo = ($i['tipo'] ?? 'opcoes') === 'contador' ? 'contador' : 'opcoes';
                $opcoes = null;
                if ($tipo === 'opcoes') {
                    $opcoes = [];
                    foreach ((array) ($i['opcoes'] ?? []) as $o) {
                        $rotulo = mb_substr(trim((string) ($o['rotulo'] ?? '')), 0, 40);
                        if ($rotulo === '') {
                            continue;
                        }
                        $opcoes[] = [
                            'id' => preg_match('/^[a-z0-9]{1,12}$/', (string) ($o['id'] ?? '')) ? $o['id'] : substr(bin2hex(random_bytes(4)), 0, 6),
                            'rotulo' => $rotulo,
                            'emoji' => mb_substr(trim((string) ($o['emoji'] ?? '')), 0, 8) ?: null,
                            'tom' => in_array($o['tom'] ?? '', self::TONS, true) ? $o['tom'] : 'bom',
                        ];
                    }
                    if (count($opcoes) < 2) {
                        throw new \DomainException("\"$nome\" precisa de pelo menos duas opções.");
                    }
                }
                $etapas = array_values(array_intersect((array) ($i['etapas'] ?? []), ['Bebês', 'Crianças bem pequenas', 'Crianças pequenas']));
                $linha = [
                    'nome' => $nome,
                    'emoji' => mb_substr(trim((string) ($i['emoji'] ?? '')), 0, 8) ?: null,
                    'tipo' => $tipo,
                    'opcoes' => $opcoes ? json_encode($opcoes, JSON_UNESCAPED_UNICODE) : null,
                    'etapas' => $etapas && count($etapas) < 3 ? implode('|', $etapas) : null,
                    'ordem' => $ordem,
                    'ativo' => !isset($i['ativo']) || $i['ativo'] ? 1 : 0,
                ];
                if (!empty($i['id']) && DB::table('agenda_itens')->where('id', (int) $i['id'])->exists()) {
                    DB::table('agenda_itens')->where('id', (int) $i['id'])->update($linha);
                    $usados[] = (int) $i['id'];
                } else {
                    $usados[] = DB::table('agenda_itens')->insertGetId($linha);
                }
            }
            // Os que saíram da lista só são desativados: a agenda dos dias anteriores continua legível.
            DB::table('agenda_itens')->whereNotIn('id', $usados ?: [0])->update(['ativo' => 0]);
        });
        return $this->itens(false);
    }

    // ── O dia da turma ──────────────────────────────────────────────────

    public function dia(int $turmaId, string $data): ?array
    {
        $turma = DB::table('turmas')->where('id', $turmaId)->first(['id', 'nome_turma', 'etapa_bncc']);
        if (!$turma) {
            return null;
        }
        $dia = DB::table('agenda_dias')->where('turma_id', $turmaId)->where('data', $data)->first();
        $itens = $this->itens(true, $turma->etapa_bncc);
        $porAluno = $dia ? DB::table('agenda_alunos')->where('dia_id', $dia->id)->get()->keyBy('aluno_id') : collect();
        $faltas = DB::table('frequencia')->where('data', $data)->where('presente', 0)->pluck('aluno_id')->map(fn ($id) => (int) $id)->all();

        $alunos = DB::table('turma_aluno as ta')->join('alunos as a', 'a.id', '=', 'ta.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('ta.turma_id', $turmaId)->where('u.ativo', 1)->orderBy('u.nome_completo')
            ->get(['a.id', 'u.nome_completo', 'a.foto_path', 'a.observacoes_saude'])
            ->map(function ($a) use ($porAluno, $faltas) {
                $r = $porAluno[$a->id] ?? null;
                return [
                    'id' => (int) $a->id,
                    'nome' => $a->nome_completo,
                    'foto_url' => FotoAlunoService::url($a->foto_path),
                    'saude' => $a->observacoes_saude,
                    'faltou' => in_array((int) $a->id, $faltas, true),
                    'valores' => (object) ($r ? (json_decode((string) $r->valores, true) ?: []) : []),
                    'recado' => $r->recado ?? null,
                    'visto_em' => $r->visto_em ?? null,
                ];
            })->all();

        return [
            'turma' => ['id' => (int) $turma->id, 'nome' => $turma->nome_turma, 'etapa' => $turma->etapa_bncc],
            'data' => $data,
            'itens' => $itens,
            'padroes' => (object) ($dia ? (json_decode((string) $dia->padroes, true) ?: []) : []),
            'recado' => $dia->recado ?? null,
            'enviado_em' => $dia->enviado_em ?? null,
            'alunos' => $alunos,
        ];
    }

    /**
     * Salva uma mudança (a tela grava a cada toque):
     * - padroes: {item_id: valor|null} — para a turma toda;
     * - recado: texto para a turma toda;
     * - aluno_id + valores {item_id: valor|null} (null = volta ao da turma) e/ou recado.
     */
    public function salvar(int $turmaId, string $data, array $p, int $usuarioId): array
    {
        $turma = DB::table('turmas')->where('id', $turmaId)->first(['etapa_bncc']);
        $itens = collect($this->itens(true, $turma->etapa_bncc ?? null))->keyBy('id');

        DB::transaction(function () use ($turmaId, $data, $p, $usuarioId, $itens) {
            DB::table('agenda_dias')->insertOrIgnore(['turma_id' => $turmaId, 'data' => $data, 'atualizado_em' => now(), 'atualizado_por' => $usuarioId]);
            $dia = DB::table('agenda_dias')->where('turma_id', $turmaId)->where('data', $data)->lockForUpdate()->first();
            $mudou = ['atualizado_em' => now(), 'atualizado_por' => $usuarioId];

            if (isset($p['padroes']) && is_array($p['padroes'])) {
                $mudou['padroes'] = json_encode((object) $this->mesclar(json_decode((string) $dia->padroes, true) ?: [], $p['padroes'], $itens));
            }
            if (array_key_exists('recado', $p) && empty($p['aluno_id'])) {
                $mudou['recado'] = $this->texto($p['recado']);
            }
            DB::table('agenda_dias')->where('id', $dia->id)->update($mudou);

            $alunoId = (int) ($p['aluno_id'] ?? 0);
            if ($alunoId) {
                if (!DB::table('turma_aluno')->where('turma_id', $turmaId)->where('aluno_id', $alunoId)->exists()) {
                    throw new \DomainException('Aluno não é desta turma.');
                }
                DB::table('agenda_alunos')->insertOrIgnore(['dia_id' => $dia->id, 'aluno_id' => $alunoId]);
                $atual = DB::table('agenda_alunos')->where('dia_id', $dia->id)->where('aluno_id', $alunoId)->first();
                $campos = [];
                if (isset($p['valores']) && is_array($p['valores'])) {
                    $campos['valores'] = json_encode((object) $this->mesclar(json_decode((string) $atual->valores, true) ?: [], $p['valores'], $itens));
                }
                if (array_key_exists('recado', $p)) {
                    $campos['recado'] = $this->texto($p['recado']);
                }
                if ($campos) {
                    DB::table('agenda_alunos')->where('id', $atual->id)->update($campos);
                }
            }
        });

        return $this->dia($turmaId, $data);
    }

    /** Envia às famílias das crianças que vieram (avisa no app); depois disso, mudanças aparecem direto. */
    public function enviar(int $turmaId, string $data, int $usuarioId): array
    {
        $d = $this->dia($turmaId, $data);
        $presentes = array_filter($d['alunos'], fn ($a) => !$a['faltou']);
        if (!$presentes) {
            throw new \DomainException('Nenhuma criança presente neste dia.');
        }
        DB::table('agenda_dias')->insertOrIgnore(['turma_id' => $turmaId, 'data' => $data, 'atualizado_em' => now(), 'atualizado_por' => $usuarioId]);
        $primeiraVez = !$d['enviado_em'];
        DB::table('agenda_dias')->where('turma_id', $turmaId)->where('data', $data)
            ->update(['enviado_em' => $d['enviado_em'] ?? now(), 'enviado_por' => $usuarioId]);

        if ($primeiraVez) {
            $resumos = [];
            foreach ($presentes as $a) {
                $resumos[$a['id']] = $this->resumo($d['itens'], $this->efetivos($d['itens'], (array) $d['padroes'], (array) $a['valores']));
            }
            $this->notificacoes->agendaEnviada($data, $resumos);
        }
        return $this->dia($turmaId, $data);
    }

    // ── Família ─────────────────────────────────────────────────────────

    /** O dia de uma criança para a família (só depois de enviado). Marca como vista. */
    public function diaDoFilho(int $alunoId, string $data): array
    {
        $turma = DB::table('turma_aluno as ta')->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->where('ta.aluno_id', $alunoId)->whereNotNull('t.etapa_bncc')->where('t.status', 'Ativa')
            ->orderByDesc('t.ano_letivo')->first(['t.id', 't.nome_turma', 't.etapa_bncc']);
        $out = ['data' => $data, 'turma' => $turma->nome_turma ?? null, 'enviada' => false, 'itens' => [], 'recado_turma' => null, 'recado' => null,
            'faltou' => false, 'dias' => $turma ? $this->diasEnviados((int) $turma->id) : []];
        if (!$turma) {
            return $out;
        }
        $dia = DB::table('agenda_dias')->where('turma_id', $turma->id)->where('data', $data)->whereNotNull('enviado_em')->first();
        $out['faltou'] = DB::table('frequencia')->where('aluno_id', $alunoId)->where('data', $data)->where('presente', 0)->exists();
        if (!$dia || $out['faltou']) {
            return $out;
        }
        $meu = DB::table('agenda_alunos')->where('dia_id', $dia->id)->where('aluno_id', $alunoId)->first();
        $itens = $this->itens(true, $turma->etapa_bncc);
        $valores = $this->efetivos($itens, json_decode((string) $dia->padroes, true) ?: [], $meu ? (json_decode((string) $meu->valores, true) ?: []) : []);

        $out['enviada'] = true;
        $out['enviada_em'] = $dia->enviado_em;
        $out['professora'] = DB::table('usuarios')->where('id', $dia->enviado_por)->value('nome_completo');
        $out['recado_turma'] = $dia->recado;
        $out['recado'] = $meu->recado ?? null;
        foreach ($itens as $i) {
            $v = $valores[$i['id']] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $opcao = $i['tipo'] === 'opcoes' ? collect($i['opcoes'])->firstWhere('id', $v) : null;
            if ($i['tipo'] === 'opcoes' && !$opcao) {
                continue;
            }
            $out['itens'][] = [
                'id' => $i['id'], 'nome' => $i['nome'], 'emoji' => $i['emoji'], 'tipo' => $i['tipo'],
                'valor' => $i['tipo'] === 'contador' ? (int) $v : $opcao['rotulo'],
                'valor_emoji' => $opcao['emoji'] ?? null,
                'tom' => $opcao['tom'] ?? null,
            ];
        }

        DB::table('agenda_alunos')->insertOrIgnore(['dia_id' => $dia->id, 'aluno_id' => $alunoId]);
        DB::table('agenda_alunos')->where('dia_id', $dia->id)->where('aluno_id', $alunoId)->whereNull('visto_em')->update(['visto_em' => now()]);
        return $out;
    }

    /** Filhos do responsável que estão em turma de Educação Infantil. */
    public function filhosNaInfantil(int $usuarioId): array
    {
        $resp = DB::table('responsaveis')->where('usuario_id', $usuarioId)->value('id');
        if (!$resp) {
            return [];
        }
        return DB::table('aluno_responsavel as ar')->join('alunos as a', 'a.id', '=', 'ar.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->join('turma_aluno as ta', 'ta.aluno_id', '=', 'a.id')->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->where('ar.responsavel_id', $resp)->where('u.ativo', 1)->whereNotNull('t.etapa_bncc')->where('t.status', 'Ativa')
            ->orderBy('u.nome_completo')->distinct()
            ->get(['a.id', 'u.nome_completo', 'a.foto_path', 't.nome_turma'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'nome' => $a->nome_completo, 'foto_url' => FotoAlunoService::url($a->foto_path), 'turma' => $a->nome_turma])
            ->unique('id')->values()->all();
    }

    public function ehFilho(int $usuarioId, int $alunoId): bool
    {
        return collect($this->filhosNaInfantil($usuarioId))->contains('id', $alunoId);
    }

    // ── Apoio ───────────────────────────────────────────────────────────

    /** Valor de cada item para uma criança: a exceção dela ou o da turma. */
    public function efetivos(array $itens, array $padroes, array $excecoes): array
    {
        $out = [];
        foreach ($itens as $i) {
            $k = (string) $i['id'];
            $out[$i['id']] = $excecoes[$k] ?? $padroes[$k] ?? null;
        }
        return $out;
    }

    /** "Almoço: comeu tudo · Sono: dormiu bem" (para o aviso no celular). */
    private function resumo(array $itens, array $valores): string
    {
        $partes = [];
        foreach ($itens as $i) {
            $v = $valores[$i['id']] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            if ($i['tipo'] === 'contador') {
                $partes[] = "{$i['nome']}: $v";
            } elseif ($o = collect($i['opcoes'])->firstWhere('id', $v)) {
                $partes[] = trim(($o['emoji'] ?? '') . ' ' . $i['nome'] . ': ' . mb_strtolower($o['rotulo']));
            }
        }
        return implode(' · ', $partes);
    }

    /** Aplica mudanças {item_id: valor|null} sobre os valores atuais, validando cada valor. */
    private function mesclar(array $atual, array $mudancas, $itens): array
    {
        foreach ($mudancas as $itemId => $valor) {
            $item = $itens[(int) $itemId] ?? null;
            if (!$item) {
                continue;
            }
            $k = (string) (int) $itemId;
            if ($valor === null || $valor === '') {
                unset($atual[$k]);
            } elseif ($item['tipo'] === 'contador') {
                $atual[$k] = max(0, min(self::MAX_CONTADOR, (int) $valor));
            } elseif (collect($item['opcoes'])->contains('id', (string) $valor)) {
                $atual[$k] = (string) $valor;
            }
        }
        return $atual;
    }

    /** Datas com agenda enviada nos últimos 30 dias (navegação da família). */
    private function diasEnviados(int $turmaId): array
    {
        return DB::table('agenda_dias')->where('turma_id', $turmaId)->whereNotNull('enviado_em')
            ->where('data', '>=', now()->subDays(30)->toDateString())->orderByDesc('data')->pluck('data')->all();
    }

    private function turmasInfantis()
    {
        return DB::table('turmas as t')->leftJoin('turma_aluno as ta', 'ta.turma_id', '=', 't.id')
            ->whereNotNull('t.etapa_bncc')->where('t.status', 'Ativa')
            ->where('t.ano_letivo', '<=', (int) now()->year)
            ->groupBy('t.id', 't.nome_turma', 't.etapa_bncc', 't.ano_letivo')
            ->orderByDesc('t.ano_letivo')->orderBy('t.nome_turma')
            ->get(['t.id', 't.nome_turma', 't.etapa_bncc', 't.ano_letivo', DB::raw('COUNT(ta.aluno_id) as alunos')])
            ->map(function ($t) {
                $t->id = (int) $t->id;
                return $t;
            });
    }

    /** @return int[] */
    private function turmasDoProfessor(int $usuarioId): array
    {
        $prof = DB::table('professores')->where('usuario_id', $usuarioId)->value('id');
        return $prof ? DB::table('turma_professor_disciplina')->where('professor_id', $prof)->distinct()->pluck('turma_id')->map(fn ($id) => (int) $id)->all() : [];
    }

    private function formatarItem(object $i): array
    {
        return [
            'id' => (int) $i->id,
            'nome' => $i->nome,
            'emoji' => $i->emoji,
            'tipo' => $i->tipo,
            'opcoes' => $i->opcoes ? json_decode($i->opcoes, true) : [],
            'etapas' => $i->etapas ? explode('|', $i->etapas) : [],
            'ativo' => (bool) $i->ativo,
        ];
    }

    private function texto($v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, 2000);
    }

    /** Rotina sugerida na primeira vez (a escola ajusta em "Itens da agenda"). */
    private function criarItensSugeridos(): void
    {
        $comer = [['c1', 'Comeu tudo', '😋', 'bom'], ['c2', 'Comeu bem', '🙂', 'bom'], ['c3', 'Comeu pouco', '😕', 'medio'], ['c4', 'Não aceitou', '🙅', 'ruim']];
        $sugeridos = [
            ['Lanche da manhã', '🍎', $comer, null],
            ['Almoço', '🍲', $comer, null],
            ['Lanche da tarde', '🥪', $comer, null],
            ['Sono', '😴', [['s1', 'Dormiu bem', '😴', 'bom'], ['s2', 'Dormiu pouco', '🥱', 'medio'], ['s3', 'Não dormiu', '👀', 'ruim']], null],
            ['Humor', '🌈', [['h1', 'Alegre', '😄', 'bom'], ['h2', 'Tranquilo', '😌', 'bom'], ['h3', 'Choroso', '😢', 'medio'], ['h4', 'Irritado', '😠', 'ruim']], null],
            ['Evacuação', '🚽', [['e1', 'Normal', '✅', 'bom'], ['e2', 'Mole', '💧', 'medio'], ['e3', 'Não evacuou', '➖', 'medio']], null],
            ['Trocas de fralda', '🧷', null, 'Bebês|Crianças bem pequenas'],
        ];
        foreach ($sugeridos as $ordem => [$nome, $emoji, $opcoes, $etapas]) {
            DB::table('agenda_itens')->insert([
                'nome' => $nome,
                'emoji' => $emoji,
                'tipo' => $opcoes ? 'opcoes' : 'contador',
                'opcoes' => $opcoes ? json_encode(array_map(fn ($o) => ['id' => $o[0], 'rotulo' => $o[1], 'emoji' => $o[2], 'tom' => $o[3]], $opcoes), JSON_UNESCAPED_UNICODE) : null,
                'etapas' => $etapas,
                'ordem' => $ordem,
                'ativo' => 1,
            ]);
        }
    }
}
