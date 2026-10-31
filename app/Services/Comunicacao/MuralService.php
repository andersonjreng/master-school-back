<?php

namespace App\Services\Comunicacao;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mural de comunicados.
 *
 * Público: escola (todos), equipe (professores + gestão), familias (todos os
 * responsáveis) ou turmas (famílias e professores das turmas escolhidas).
 * Gestão publica para qualquer público; professor publica para a equipe ou
 * para as turmas dele. Família não publica nem comenta: lê, reage e, quando
 * o comunicado pede, confirma ciência.
 *
 * Publicação agendada (publicar_em): até a hora, só o autor e a gestão veem;
 * o aviso no celular sai uma vez, na hora (avisarAgendados, chamado pelo
 * pulso da Central e pelo comando mural:publicar-agendados).
 *
 * Enquete: o comunicado pode ter opções para votar (uma ou várias). O
 * resultado aparece para quem votou (ou só para quem publicou) e sempre
 * para o autor e a gestão, com quem votou em quê.
 */
class MuralService
{
    public const PUBLICOS = ['escola', 'equipe', 'familias', 'turmas'];
    public const REACOES = ['👍', '❤️', '👏', '🎉', '🙏'];
    private const TIPOS_ANEXO = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    // ── Leitura ────────────────────────────────────────────────────────────

    /** @return array{posts: array, total: int, nao_lidos: int} */
    public function feed(PerfilComunicacao $p, string $filtro = 'todos', int $pagina = 1, int $porPagina = 15): array
    {
        $visiveis = $this->visiveis($p);
        if ($filtro === 'nao_lidos') {
            $visiveis = $visiveis->filter(fn ($post) => !$post->lido_em && $post->para_mim);
        } elseif ($filtro === 'ciencia') {
            $visiveis = $visiveis->filter(fn ($post) => $post->exige_ciencia && !$post->ciente_em && $post->para_mim && (int) $post->autor_usuario_id !== $p->usuarioId);
        } elseif ($filtro === 'meus') {
            $visiveis = $visiveis->filter(fn ($post) => (int) $post->autor_usuario_id === $p->usuarioId);
        }
        // Fixados primeiro, depois os mais recentes.
        $ordenados = $visiveis->sortBy([['fixado', 'desc'], ['publicado_em', 'desc']])->values();
        $pagina = max(1, $pagina);
        $fatia = $ordenados->slice(($pagina - 1) * $porPagina, $porPagina)->values();

        return [
            'posts' => $this->formatar($fatia, $p),
            'total' => $ordenados->count(),
            'nao_lidos' => $this->visiveis($p)->filter(fn ($x) => !$x->lido_em && $x->para_mim)->count(),
        ];
    }

    /** Posts que o usuário pode ver (com o estado de leitura dele). */
    public function visiveis(PerfilComunicacao $p): Collection
    {
        $hoje = now()->toDateString();
        $posts = DB::table('mural_posts as m')
            ->leftJoin('mural_leituras as l', fn ($j) => $j->on('l.post_id', '=', 'm.id')->where('l.usuario_id', $p->usuarioId))
            ->select('m.*', 'l.lido_em', 'l.ciente_em')
            ->orderByDesc('m.criado_em')->limit(500)->get();

        $agora = now()->toDateTimeString();
        return $posts->filter(function ($m) use ($p, $hoje, $agora) {
            $autor = (int) $m->autor_usuario_id === $p->usuarioId;
            $m->agendado = !empty($m->publicar_em) && $m->publicar_em > $agora;
            $m->publicado_em = !empty($m->publicar_em) ? max($m->publicar_em, $m->criado_em) : $m->criado_em;
            if ($m->agendado) {
                // Agendado: só o autor e a gestão veem, e ninguém é cobrado por ele ainda.
                $m->para_mim = false;
                return $autor || $p->gestor;
            }
            // para_mim: o post é destinado a este usuário (conta em "não lidos" e "ciência").
            // A gestão vê tudo para moderar, mas só é cobrada pelo que é para ela.
            $m->para_mim = $autor || (!$m->oculto && !($m->valido_ate && $m->valido_ate < $hoje) && $this->alcanca($m, $p));
            if ($autor || $p->gestor) {
                return true; // autor e gestão veem tudo (inclusive ocultos/expirados, sinalizados)
            }
            if ($m->oculto || ($m->valido_ate && $m->valido_ate < $hoje)) {
                return false;
            }
            return $this->alcanca($m, $p);
        })->values();
    }

    /** O post alcança este usuário pelo público? */
    private function alcanca(object $m, PerfilComunicacao $p): bool
    {
        $turmas = json_decode((string) ($m->turmas ?? '[]'), true) ?: [];
        return match ($m->publico) {
            'escola' => true,
            'equipe' => $p->ehEquipe(),
            'familias' => $p->ehFamilia(),
            'turmas' => (bool) array_intersect($turmas, array_merge($p->turmasProfessor, $p->turmasFamilia)),
            default => false,
        };
    }

    public function buscar(int $id, PerfilComunicacao $p): ?array
    {
        $post = $this->visiveis($p)->firstWhere('id', $id);
        return $post ? $this->formatar(collect([$post]), $p)[0] : null;
    }

    // ── Escrita ────────────────────────────────────────────────────────────

    /** @return array|string post criado, ou erro */
    public function salvar(PerfilComunicacao $p, array $dados, ?UploadedFile $anexo, ?int $id = null): array|string
    {
        if (!$p->ehEquipe()) {
            return 'Somente a equipe da escola publica no mural.';
        }
        $titulo = trim((string) ($dados['titulo'] ?? ''));
        $texto = trim((string) ($dados['texto'] ?? ''));
        $publico = (string) ($dados['publico'] ?? '');
        $turmas = array_values(array_unique(array_map('intval', (array) ($dados['turmas'] ?? []))));

        $turbinado = $this->turbinado();
        $enquete = $turbinado && $this->marcado($dados['enquete'] ?? null);
        $opcoes = $enquete ? $this->opcoesEnquete($dados['opcoes'] ?? []) : [];

        if ($titulo === '' || ($texto === '' && !$enquete)) {
            return $enquete ? 'Escreva a pergunta da enquete.' : 'Escreva o título e o texto do comunicado.';
        }
        if ($enquete && (count($opcoes) < 2 || count($opcoes) > 8)) {
            return 'A enquete precisa de 2 a 8 opções.';
        }
        if (!in_array($publico, self::PUBLICOS, true)) {
            return 'Escolha para quem é o comunicado.';
        }
        if ($publico === 'turmas' && !$turmas) {
            return 'Escolha pelo menos uma turma.';
        }
        // Professor: só equipe ou as turmas dele.
        if (!$p->gestor) {
            if (!in_array($publico, ['equipe', 'turmas'], true)) {
                return 'Professores publicam para a equipe ou para as famílias das suas turmas.';
            }
            if ($publico === 'turmas' && array_diff($turmas, $p->turmasProfessor)) {
                return 'Você só pode publicar para as suas turmas.';
            }
        }

        $atual = $id ? DB::table('mural_posts')->where('id', $id)->first() : null;
        if ($id && (!$atual || ((int) $atual->autor_usuario_id !== $p->usuarioId && !$p->gestor))) {
            return 'Comunicado não encontrado.';
        }

        $campos = [
            'titulo' => mb_substr($titulo, 0, 200),
            'texto' => mb_substr($texto, 0, 8000),
            'publico' => $publico,
            'turmas' => $publico === 'turmas' ? json_encode($turmas) : null,
            'fixado' => $p->gestor && !empty($dados['fixado']) && $dados['fixado'] !== 'false' ? 1 : 0,
            'valido_ate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($dados['valido_ate'] ?? '')) ? $dados['valido_ate'] : null,
            'exige_ciencia' => !empty($dados['exige_ciencia']) && $dados['exige_ciencia'] !== 'false' ? 1 : 0,
        ];

        // Agendar só vale enquanto o comunicado não saiu (editar um publicado não o "desagenda").
        $jaSaiu = $atual && (!$atual->publicar_em || $atual->publicar_em <= now()->toDateTimeString());
        $agendarPara = null;
        if ($turbinado && !$jaSaiu) {
            $agendarPara = $this->dataHora($dados['publicar_em'] ?? null);
            if ($agendarPara && $agendarPara <= now()->toDateTimeString()) {
                $agendarPara = null; // hora que já passou: publica agora
            }
            if ($agendarPara && $agendarPara > now()->addDays(60)->toDateTimeString()) {
                return 'Agende para no máximo 60 dias à frente.';
            }
            $campos['publicar_em'] = $agendarPara;
            $campos['avisado_em'] = $agendarPara ? null : now();
        }
        if ($turbinado) {
            $campos['enquete'] = $enquete ? 1 : 0;
            $campos['enquete_multipla'] = $enquete && $this->marcado($dados['enquete_multipla'] ?? null) ? 1 : 0;
            $campos['enquete_resultado'] = ($dados['enquete_resultado'] ?? '') === 'autor' ? 'autor' : 'votou';
            if ($atual && $atual->enquete && $enquete) {
                $antes = DB::table('mural_enquete_opcoes')->where('post_id', $id)->orderBy('ordem')->pluck('texto')->all();
                if ($antes !== $opcoes && DB::table('mural_votos')->where('post_id', $id)->exists()) {
                    return 'A enquete já tem votos: as opções não podem mais mudar.';
                }
            }
        }

        if ($anexo) {
            if (!in_array($anexo->getMimeType(), self::TIPOS_ANEXO, true)) {
                return 'Anexo deve ser imagem (JPG, PNG, WEBP, GIF) ou PDF.';
            }
            if ($anexo->getSize() > 10 * 1024 * 1024) {
                return 'O anexo pode ter até 10 MB.';
            }
            $ext = strtolower($anexo->getClientOriginalExtension() ?: ($anexo->getMimeType() === 'application/pdf' ? 'pdf' : 'jpg'));
            $caminho = $anexo->storeAs('mural', Str::uuid() . '.' . $ext, 'public');
            if ($atual?->anexo_path) {
                Storage::disk('public')->delete($atual->anexo_path);
            }
            $campos += ['anexo_path' => $caminho, 'anexo_nome' => mb_substr($anexo->getClientOriginalName(), 0, 200), 'anexo_tipo' => $anexo->getMimeType()];
        } elseif (!empty($dados['remover_anexo']) && $atual?->anexo_path) {
            Storage::disk('public')->delete($atual->anexo_path);
            $campos += ['anexo_path' => null, 'anexo_nome' => null, 'anexo_tipo' => null];
        }

        if ($atual) {
            DB::table('mural_posts')->where('id', $id)->update($campos);
            // Era agendado e passou a sair agora: avisa já.
            if ($turbinado && !$jaSaiu && !$agendarPara) {
                $this->avisar(DB::table('mural_posts')->where('id', $id)->first());
            }
        } else {
            $id = DB::table('mural_posts')->insertGetId($campos + ['autor_usuario_id' => $p->usuarioId]);
            // Autor já "leu" o próprio post.
            DB::table('mural_leituras')->insertOrIgnore(['post_id' => $id, 'usuario_id' => $p->usuarioId]);
            // Comunicado novo: aviso no celular de quem recebe (editar não avisa de novo; agendado avisa na hora).
            if (!$agendarPara) {
                $this->avisar(DB::table('mural_posts')->where('id', $id)->first());
            }
        }
        if ($turbinado) {
            $this->gravarOpcoes($id, $enquete ? $opcoes : []);
        }
        return $this->buscar($id, $p);
    }

    /** Aviso no celular de quem recebe o comunicado. */
    private function avisar(object $post): void
    {
        $titulo = ($post->enquete ?? false ? 'Enquete: ' : 'Comunicado: ') . $post->titulo;
        app(\App\Services\Notificacoes\PushService::class)->avisar(
            $this->destinatarios($post)->pluck('usuario_id')->all(),
            $titulo, mb_substr($post->texto !== '' ? $post->texto : 'Toque para responder.', 0, 140), '/comunicacao', "mural:{$post->id}",
        );
    }

    /**
     * Comunicados agendados cuja hora chegou: manda o aviso (uma vez só — a
     * marcação em avisado_em é atômica, então dois chamados juntos não duplicam).
     */
    public function avisarAgendados(): int
    {
        if (!$this->turbinado()) {
            return 0;
        }
        $agora = now()->toDateTimeString();
        $n = 0;
        foreach (DB::table('mural_posts')->whereNotNull('publicar_em')->where('publicar_em', '<=', $agora)->whereNull('avisado_em')->get() as $post) {
            if (DB::table('mural_posts')->where('id', $post->id)->whereNull('avisado_em')->update(['avisado_em' => $agora])) {
                if (!$post->oculto) {
                    $this->avisar($post);
                }
                $n++;
            }
        }
        return $n;
    }

    // ── Enquete ────────────────────────────────────────────────────────────

    /** Vota (troca o voto; lista vazia tira o voto). */
    public function votar(int $id, array $opcaoIds, PerfilComunicacao $p): array|string
    {
        $m = $this->visiveis($p)->firstWhere('id', $id);
        if (!$m || !($m->enquete ?? false)) {
            return 'Enquete não encontrada.';
        }
        if (!$m->para_mim || (int) $m->autor_usuario_id === $p->usuarioId) {
            return 'Esta enquete não é para você responder.';
        }
        if ($m->enquete_encerrada_em || ($m->valido_ate && $m->valido_ate < now()->toDateString())) {
            return 'Esta enquete já foi encerrada.';
        }
        $validas = DB::table('mural_enquete_opcoes')->where('post_id', $id)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $escolhidas = array_values(array_intersect(array_unique(array_map('intval', $opcaoIds)), $validas));
        if (!$m->enquete_multipla && count($escolhidas) > 1) {
            return 'Escolha só uma opção.';
        }
        DB::transaction(function () use ($id, $escolhidas, $p) {
            DB::table('mural_votos')->where('post_id', $id)->where('usuario_id', $p->usuarioId)->delete();
            foreach ($escolhidas as $o) {
                DB::table('mural_votos')->insert(['opcao_id' => $o, 'usuario_id' => $p->usuarioId, 'post_id' => $id, 'criado_em' => now()]);
            }
        });
        DB::table('mural_leituras')->insertOrIgnore(['post_id' => $id, 'usuario_id' => $p->usuarioId]);
        return $this->buscar($id, $p);
    }

    public function encerrarEnquete(int $id, bool $encerrar, PerfilComunicacao $p): array|string
    {
        $m = DB::table('mural_posts')->where('id', $id)->first();
        if (!$m || !($m->enquete ?? false) || ((int) $m->autor_usuario_id !== $p->usuarioId && !$p->gestor)) {
            return 'Enquete não encontrada.';
        }
        DB::table('mural_posts')->where('id', $id)->update(['enquete_encerrada_em' => $encerrar ? now() : null]);
        return $this->buscar($id, $p);
    }

    /** Para o autor/gestão: quem votou em cada opção e quem do público ainda não votou. */
    public function votos(int $id, PerfilComunicacao $p): array|string
    {
        $m = DB::table('mural_posts')->where('id', $id)->first();
        if (!$m || !($m->enquete ?? false) || ((int) $m->autor_usuario_id !== $p->usuarioId && !$p->gestor)) {
            return 'Enquete não encontrada.';
        }
        $publico = $this->destinatarios($m)->keyBy('usuario_id');
        $votos = DB::table('mural_votos as v')->join('usuarios as u', 'u.id', '=', 'v.usuario_id')
            ->where('v.post_id', $id)->orderBy('u.nome_completo')->get(['v.opcao_id', 'v.usuario_id', 'u.nome_completo', 'v.criado_em']);
        $pessoa = fn ($v) => ['usuario_id' => (int) $v->usuario_id, 'nome' => $v->nome_completo,
            'detalhe' => $publico[$v->usuario_id]->detalhe ?? ($publico[$v->usuario_id]->grupo ?? null), 'em' => $v->criado_em];
        $opcoes = DB::table('mural_enquete_opcoes')->where('post_id', $id)->orderBy('ordem')->get()
            ->map(fn ($o) => ['id' => (int) $o->id, 'texto' => $o->texto,
                'pessoas' => $votos->where('opcao_id', $o->id)->map($pessoa)->values()->all()])->all();
        $votaram = $votos->pluck('usuario_id')->unique()->map(fn ($x) => (int) $x)->all();
        $faltam = $publico->reject(fn ($u) => in_array((int) $u->usuario_id, $votaram, true))
            ->map(fn ($u) => ['usuario_id' => (int) $u->usuario_id, 'nome' => $u->nome, 'detalhe' => $u->detalhe ?? $u->grupo])
            ->sortBy('nome')->values()->all();
        return ['total' => $publico->count(), 'votaram' => count($votaram), 'opcoes' => $opcoes, 'faltam' => $faltam];
    }

    /** Opções da enquete vindas do formulário (JSON ou lista), limpas. */
    private function opcoesEnquete($bruto): array
    {
        $lista = is_string($bruto) ? (json_decode($bruto, true) ?: []) : (array) $bruto;
        $out = [];
        foreach ($lista as $o) {
            $t = mb_substr(trim((string) $o), 0, 120);
            if ($t !== '' && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** Deixa as opções como a lista (mantém os ids das que não mudaram, para não perder votos). */
    private function gravarOpcoes(int $postId, array $opcoes): void
    {
        $atuais = DB::table('mural_enquete_opcoes')->where('post_id', $postId)->get()->keyBy('texto');
        $manter = [];
        foreach ($opcoes as $ordem => $texto) {
            if ($o = $atuais->get($texto)) {
                DB::table('mural_enquete_opcoes')->where('id', $o->id)->update(['ordem' => $ordem]);
                $manter[] = (int) $o->id;
            } else {
                $manter[] = DB::table('mural_enquete_opcoes')->insertGetId(['post_id' => $postId, 'ordem' => $ordem, 'texto' => $texto]);
            }
        }
        $sair = DB::table('mural_enquete_opcoes')->where('post_id', $postId)->whereNotIn('id', $manter ?: [0])->pluck('id');
        if ($sair->isNotEmpty()) {
            DB::table('mural_votos')->whereIn('opcao_id', $sair)->delete();
            DB::table('mural_enquete_opcoes')->whereIn('id', $sair)->delete();
        }
    }

    private function marcado($v): bool
    {
        return !empty($v) && $v !== 'false' && $v !== '0';
    }

    /** "2026-11-03 07:00" ou "2026-11-03T07:00" → "2026-11-03 07:00:00" (null se inválido). */
    private function dataHora($v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $v, $m)) {
            return null;
        }
        return strtotime("{$m[1]} {$m[2]}") ? "{$m[1]} {$m[2]}:00" : null;
    }

    /** @var array<string, float> por banco: última conferência dos agendados */
    private static array $conferidoEm = [];

    /** avisarAgendados no máximo a cada 30 s por escola (o pulso chama a todo instante). */
    public function avisarAgendadosDeTempoEmTempo(): void
    {
        $banco = (string) config('database.connections.mysql.database');
        if (microtime(true) - (self::$conferidoEm[$banco] ?? 0) < 30) {
            return;
        }
        self::$conferidoEm[$banco] = microtime(true);
        try {
            $this->avisarAgendados();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private ?bool $temTurbinado = null;

    /** A escola já rodou a migration do mural turbinado (agendar e enquete)? */
    public function turbinado(): bool
    {
        return $this->temTurbinado ??= Schema::hasTable('mural_votos');
    }

    public function excluir(int $id, PerfilComunicacao $p): ?string
    {
        $m = DB::table('mural_posts')->where('id', $id)->first();
        if (!$m || ((int) $m->autor_usuario_id !== $p->usuarioId && !$p->gestor)) {
            return 'Comunicado não encontrado.';
        }
        if ($m->anexo_path) {
            Storage::disk('public')->delete($m->anexo_path);
        }
        DB::table('mural_reacoes')->where('post_id', $id)->delete();
        DB::table('mural_leituras')->where('post_id', $id)->delete();
        if ($this->turbinado()) {
            DB::table('mural_votos')->where('post_id', $id)->delete();
            DB::table('mural_enquete_opcoes')->where('post_id', $id)->delete();
        }
        DB::table('mural_posts')->where('id', $id)->delete();
        return null;
    }

    /** Moderação da gestão: oculta/mostra sem apagar. */
    public function ocultar(int $id, bool $oculto, PerfilComunicacao $p): array|string
    {
        if (!$p->gestor) {
            return 'Somente a direção modera o mural.';
        }
        DB::table('mural_posts')->where('id', $id)->update(['oculto' => $oculto ? 1 : 0, 'oculto_por' => $oculto ? $p->usuarioId : null]);
        return $this->buscar($id, $p) ?? 'Comunicado não encontrado.';
    }

    public function marcarLido(int $id, PerfilComunicacao $p, bool $ciente = false): array|string
    {
        if (!$this->visiveis($p)->firstWhere('id', $id)) {
            return 'Comunicado não encontrado.';
        }
        DB::table('mural_leituras')->insertOrIgnore(['post_id' => $id, 'usuario_id' => $p->usuarioId]);
        if ($ciente) {
            DB::table('mural_leituras')->where('post_id', $id)->where('usuario_id', $p->usuarioId)->whereNull('ciente_em')->update(['ciente_em' => now()]);
        }
        return $this->buscar($id, $p);
    }

    public function marcarTodosLidos(PerfilComunicacao $p): int
    {
        $ids = $this->visiveis($p)->filter(fn ($x) => !$x->lido_em && $x->para_mim)->pluck('id');
        foreach ($ids as $id) {
            DB::table('mural_leituras')->insertOrIgnore(['post_id' => $id, 'usuario_id' => $p->usuarioId]);
        }
        return $ids->count();
    }

    public function reagir(int $id, string $emoji, PerfilComunicacao $p): array|string
    {
        if (!$this->visiveis($p)->firstWhere('id', $id)) {
            return 'Comunicado não encontrado.';
        }
        $atual = DB::table('mural_reacoes')->where('post_id', $id)->where('usuario_id', $p->usuarioId)->value('emoji');
        if ($emoji === '' || $atual === $emoji) {
            DB::table('mural_reacoes')->where('post_id', $id)->where('usuario_id', $p->usuarioId)->delete(); // tirar a reação
        } elseif (in_array($emoji, self::REACOES, true)) {
            DB::table('mural_reacoes')->updateOrInsert(['post_id' => $id, 'usuario_id' => $p->usuarioId], ['emoji' => $emoji, 'criado_em' => now()]);
        } else {
            return 'Reação inválida.';
        }
        DB::table('mural_leituras')->insertOrIgnore(['post_id' => $id, 'usuario_id' => $p->usuarioId]);
        return $this->buscar($id, $p);
    }

    /** Para o autor/gestão: quem do público confirmou ciência e quem falta. */
    public function confirmacoes(int $id, PerfilComunicacao $p): array|string
    {
        $m = DB::table('mural_posts')->where('id', $id)->first();
        if (!$m || ((int) $m->autor_usuario_id !== $p->usuarioId && !$p->gestor)) {
            return 'Comunicado não encontrado.';
        }
        $publico = $this->destinatarios($m);
        $leituras = DB::table('mural_leituras')->where('post_id', $id)->get()->keyBy('usuario_id');
        $lista = $publico->map(function ($u) use ($leituras) {
            $l = $leituras->get($u->usuario_id);
            return [
                'usuario_id' => (int) $u->usuario_id, 'nome' => $u->nome, 'grupo' => $u->grupo, 'detalhe' => $u->detalhe,
                'lido_em' => $l->lido_em ?? null, 'ciente_em' => $l->ciente_em ?? null,
            ];
        })->sortBy([['ciente_em', 'asc'], ['nome', 'asc']])->values()->all();
        return ['total' => count($lista), 'lidos' => count(array_filter($lista, fn ($x) => $x['lido_em'])), 'cientes' => count(array_filter($lista, fn ($x) => $x['ciente_em'])), 'pessoas' => $lista];
    }

    /** Pessoas alcançadas pelo post (sem o autor). */
    public function destinatarios(object $m): Collection
    {
        $turmas = json_decode((string) ($m->turmas ?? '[]'), true) ?: [];
        $familias = fn (?array $ts) => DB::table('responsaveis as r')->join('usuarios as u', 'u.id', '=', 'r.usuario_id')
            ->join('aluno_responsavel as ar', 'ar.responsavel_id', '=', 'r.id')
            ->join('turma_aluno as ta', 'ta.aluno_id', '=', 'ar.aluno_id')
            ->join('turmas as t', 't.id', '=', 'ta.turma_id')
            ->join('alunos as a', 'a.id', '=', 'ar.aluno_id')->join('usuarios as ua', 'ua.id', '=', 'a.usuario_id')
            ->where('u.ativo', 1)->where('ua.ativo', 1)->where('t.status', 'Ativa')
            ->when($ts !== null, fn ($q) => $q->whereIn('ta.turma_id', $ts ?: [0]))
            ->groupBy('r.usuario_id', 'u.nome_completo')
            ->selectRaw("r.usuario_id, u.nome_completo AS nome, 'Família' AS grupo, GROUP_CONCAT(DISTINCT CONCAT(ua.nome_completo, ' (', t.nome_turma, ')') SEPARATOR ', ') AS detalhe")
            ->get();
        $equipe = fn (?array $ts) => DB::table('usuarios as u')->join('usuario_funcao as uf', 'uf.usuario_id', '=', 'u.id')
            ->where('u.ativo', 1)->whereIn('uf.funcao_id', [1, 2, 3])
            ->when($ts !== null, fn ($q) => $q->whereIn('u.id', DB::table('turma_professor_disciplina as tpd')->join('professores as pr', 'pr.id', '=', 'tpd.professor_id')
                ->whereIn('tpd.turma_id', $ts ?: [0])->select('pr.usuario_id')))
            ->groupBy('u.id', 'u.nome_completo')
            ->selectRaw("u.id AS usuario_id, u.nome_completo AS nome, 'Equipe' AS grupo, NULL AS detalhe")
            ->get();

        $todos = match ($m->publico) {
            'escola' => $familias(null)->concat($equipe(null)),
            'equipe' => $equipe(null),
            'familias' => $familias(null),
            'turmas' => $familias($turmas)->concat($equipe($turmas)),
            default => collect(),
        };
        return $todos->reject(fn ($u) => (int) $u->usuario_id === (int) $m->autor_usuario_id)->unique('usuario_id')->values();
    }

    // ── Formatação ─────────────────────────────────────────────────────────

    /**
     * Enquete do post para quem está vendo: as opções, o que a pessoa escolheu e,
     * quando ela pode ver, os votos de cada opção.
     */
    private function formatarEnquete(object $m, Collection $opcoes, Collection $votos, PerfilComunicacao $p, bool $podeGerir, string $hoje): array
    {
        $meus = $votos->where('usuario_id', $p->usuarioId)->pluck('opcao_id')->map(fn ($x) => (int) $x)->values()->all();
        $encerrada = (bool) $m->enquete_encerrada_em || ($m->valido_ate && $m->valido_ate < $hoje);
        $verResultado = $podeGerir || ($m->enquete_resultado !== 'autor' && ($meus || $encerrada));
        $votantes = $votos->pluck('usuario_id')->unique()->count();
        return [
            'multipla' => (bool) $m->enquete_multipla,
            'resultado' => $m->enquete_resultado,
            'encerrada' => $encerrada,
            'pode_votar' => ($m->para_mim ?? false) && (int) $m->autor_usuario_id !== $p->usuarioId && !$encerrada,
            'meus' => $meus,
            'votantes' => $verResultado ? $votantes : null,
            'opcoes' => $opcoes->map(fn ($o) => [
                'id' => (int) $o->id,
                'texto' => $o->texto,
                'votos' => $verResultado ? $votos->where('opcao_id', $o->id)->count() : null,
            ])->values()->all(),
        ];
    }

    private function formatar(Collection $posts, PerfilComunicacao $p): array
    {
        if ($posts->isEmpty()) {
            return [];
        }
        $ids = $posts->pluck('id');
        $autores = DB::table('usuarios as u')->leftJoin('usuario_funcao as uf', 'uf.usuario_id', '=', 'u.id')
            ->whereIn('u.id', $posts->pluck('autor_usuario_id')->unique())
            ->select('u.id', 'u.nome_completo', DB::raw('MIN(uf.funcao_id) AS funcao'))->groupBy('u.id', 'u.nome_completo')->get()->keyBy('id');
        $reacoes = DB::table('mural_reacoes')->whereIn('post_id', $ids)->get()->groupBy('post_id');
        $leituras = DB::table('mural_leituras')->whereIn('post_id', $ids)
            ->selectRaw('post_id, COUNT(*) AS lidos, SUM(ciente_em IS NOT NULL) AS cientes')->groupBy('post_id')->get()->keyBy('post_id');
        $nomesTurmas = DB::table('turmas')->pluck('nome_turma', 'id');
        $hoje = now()->toDateString();
        $comEnquete = $this->turbinado() ? $posts->filter(fn ($m) => $m->enquete)->pluck('id') : collect();
        $opcoes = $comEnquete->isNotEmpty() ? DB::table('mural_enquete_opcoes')->whereIn('post_id', $comEnquete)->orderBy('ordem')->get()->groupBy('post_id') : collect();
        $votos = $comEnquete->isNotEmpty() ? DB::table('mural_votos')->whereIn('post_id', $comEnquete)->get(['post_id', 'opcao_id', 'usuario_id'])->groupBy('post_id') : collect();

        return $posts->map(function ($m) use ($p, $autores, $reacoes, $leituras, $nomesTurmas, $hoje, $opcoes, $votos) {
            $autor = $autores->get($m->autor_usuario_id);
            $doPost = $reacoes->get($m->id, collect());
            $minha = $doPost->firstWhere('usuario_id', $p->usuarioId)?->emoji;
            $podeGerir = (int) $m->autor_usuario_id === $p->usuarioId || $p->gestor;
            $turmas = json_decode((string) ($m->turmas ?? '[]'), true) ?: [];
            $l = $leituras->get($m->id);
            return [
                'id' => (int) $m->id,
                'titulo' => $m->titulo,
                'texto' => $m->texto,
                'publico' => $m->publico,
                'turmas' => array_map(fn ($t) => ['id' => (int) $t, 'nome' => $nomesTurmas[$t] ?? '?'], $turmas),
                'fixado' => (bool) $m->fixado,
                'valido_ate' => $m->valido_ate,
                'expirado' => $m->valido_ate && $m->valido_ate < $hoje,
                'exige_ciencia' => (bool) $m->exige_ciencia,
                'oculto' => (bool) $m->oculto,
                'anexo' => $m->anexo_path ? ['url' => url('storage/' . $m->anexo_path), 'nome' => $m->anexo_nome, 'imagem' => str_starts_with((string) $m->anexo_tipo, 'image/')] : null,
                'autor' => [
                    'id' => (int) $m->autor_usuario_id,
                    'nome' => $autor->nome_completo ?? 'Escola',
                    'papel' => match ((int) ($autor->funcao ?? 0)) { 1 => 'Administração', 2 => 'Direção', 3 => 'Professor(a)', default => 'Escola' },
                ],
                'criado_em' => $m->publicado_em ?? $m->criado_em,
                'agendado' => (bool) ($m->agendado ?? false),
                'publicar_em' => $m->publicar_em ?? null,
                'enquete' => !empty($m->enquete) ? $this->formatarEnquete($m, $opcoes->get($m->id, collect()), $votos->get($m->id, collect()), $p, $podeGerir, $hoje) : null,
                'editado' => (bool) $m->atualizado_em,
                'lido' => (bool) $m->lido_em || !($m->para_mim ?? true),
                'para_mim' => (bool) ($m->para_mim ?? true),
                'ciente' => (bool) $m->ciente_em,
                'reacoes' => collect(self::REACOES)->map(fn ($e) => ['emoji' => $e, 'total' => $doPost->where('emoji', $e)->count()])
                    ->filter(fn ($r) => $r['total'] > 0)->values()->all(),
                'minha_reacao' => $minha,
                'pode_gerir' => $podeGerir,
                // Contadores de leitura só para quem publicou / gestão.
                'estatisticas' => $podeGerir ? ['lidos' => max(0, (int) ($l->lidos ?? 0) - 1), 'cientes' => (int) ($l->cientes ?? 0)] : null,
            ];
        })->values()->all();
    }
}
