<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Comunicacao\MuralService;
use App\Services\Comunicacao\OcorrenciaService;
use App\Services\Comunicacao\PerfilComunicacao;
use App\Services\Comunicacao\RecadoService;
use App\Services\Notificacoes\NotificacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central de Comunicação: mural, ocorrências, recados e o resumo do sino.
 * As permissões ficam nos serviços (PerfilComunicacao diz quem é o usuário).
 */
class ComunicacaoController extends Controller
{
    public function __construct(
        private MuralService $mural,
        private OcorrenciaService $ocorrencias,
        private RecadoService $recados,
        private NotificacaoService $notificacoes,
    ) {}

    private function perfil(Request $r): PerfilComunicacao
    {
        return PerfilComunicacao::de($r->attributes->get('max_user'));
    }

    private function resposta(array|string|null $r, int $ok = 200): JsonResponse
    {
        if (is_string($r)) {
            return response()->json(['error' => $r], 422);
        }
        if ($r === null) {
            return response()->json(['error' => 'Não encontrado.'], 404);
        }
        return response()->json(['success' => true, 'data' => $r], $ok);
    }

    // ── Sino / resumo ──────────────────────────────────────────────────────

    /** Assinaturas por área; o front só recarrega o que mudou. */
    public function pulso(\App\Services\Comunicacao\PulsoComunicacao $pulso): JsonResponse
    {
        return response()->json(['data' => $pulso->versoes()]);
    }

    public function resumo(Request $request): JsonResponse
    {
        $p = $this->perfil($request);
        $visiveis = $this->mural->visiveis($p);
        $naoLidos = $visiveis->filter(fn ($m) => !$m->lido_em && $m->para_mim);
        $cienciaMural = $visiveis->filter(fn ($m) => $m->exige_ciencia && !$m->ciente_em && $m->para_mim && (int) $m->autor_usuario_id !== $p->usuarioId);

        $conversas = $this->recados->conversas($p);
        $comNaoLidas = array_values(array_filter($conversas, fn ($c) => $c['nao_lidas'] > 0));

        $ocorrencias = [];
        if ($p->ehFamilia()) {
            $ocorrencias = $this->ocorrencias->listar($p, ['pendentes' => 1, 'limite' => 10]);
        } elseif ($p->gestor) {
            $ocorrencias = array_values(array_filter($this->ocorrencias->listar($p, ['nivel' => 'grave', 'limite' => 20]),
                fn ($o) => $o['data'] >= now()->subDays(7)->toDateString()));
        }

        $itens = [];
        foreach ($cienciaMural->take(5) as $m) {
            $itens[] = ['tipo' => 'mural_ciencia', 'id' => (int) $m->id, 'titulo' => $m->titulo, 'detalhe' => 'Confirme a leitura deste comunicado', 'quando' => $m->criado_em];
        }
        foreach ($naoLidos->reject(fn ($m) => $m->exige_ciencia)->take(5) as $m) {
            $itens[] = ['tipo' => 'mural', 'id' => (int) $m->id, 'titulo' => $m->titulo, 'detalhe' => 'Novo comunicado no mural', 'quando' => $m->criado_em];
        }
        foreach (array_slice($comNaoLidas, 0, 5) as $c) {
            $itens[] = ['tipo' => 'recado', 'id' => $c['id'], 'titulo' => $c['assunto'], 'detalhe' => "{$c['nao_lidas']} mensagem(ns) · {$c['aluno']}", 'quando' => $c['ultima_mensagem_em']];
        }
        foreach (array_slice($ocorrencias, 0, 5) as $o) {
            $itens[] = ['tipo' => 'ocorrencia', 'id' => $o['id'], 'titulo' => $o['titulo'],
                'detalhe' => $p->ehFamilia() ? "{$o['aluno']} · confirme que está ciente" : "Grave · {$o['aluno']} ({$o['turma']})", 'quando' => $o['criado_em']];
        }
        usort($itens, fn ($a, $b) => strcmp((string) $b['quando'], (string) $a['quando']));

        // Notificações: família (nota, avaliação, falta, boleto) e equipe
        // (pendências do perfil). As que dependem do relógio são
        // recalculadas aqui, no máximo a cada 10 min.
        $this->notificacoes->sincronizar($p->usuarioId, $this->funcoes($request));
        $notif = $this->notificacoes->doUsuario($p->usuarioId);

        return response()->json(['success' => true, 'data' => [
            'mural_nao_lidos' => $naoLidos->count(),
            'mural_ciencia' => $cienciaMural->count(),
            'recados_nao_lidos' => array_sum(array_column($conversas, 'nao_lidas')),
            'ocorrencias' => count($ocorrencias),
            'notificacoes_nao_lidas' => $notif['nao_lidas'],
            'notificacoes' => $notif['itens'],
            'total' => $naoLidos->count() + array_sum(array_column($conversas, 'nao_lidas')) + count($ocorrencias) + $notif['nao_lidas'],
            'itens' => array_slice($itens, 0, 10),
            'perfil' => ['gestor' => $p->gestor, 'professor' => $p->professorId !== null, 'familia' => $p->ehFamilia()],
        ]]);
    }

    public function notificacaoLida(Request $request, int $id): JsonResponse
    {
        $this->notificacoes->marcarLida($this->perfil($request)->usuarioId, $id);
        return response()->json(['success' => true]);
    }

    public function notificacoesLidas(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'marcadas' => $this->notificacoes->marcarTodasLidas($this->perfil($request)->usuarioId)]);
    }

    // ── Mural ──────────────────────────────────────────────────────────────

    public function mural(Request $request): JsonResponse
    {
        $p = $this->perfil($request);
        return $this->resposta($this->mural->feed($p, (string) $request->query('filtro', 'todos'), (int) $request->query('pagina', 1)));
    }

    /** "Escreve pra mim": o Max desenvolve o rascunho (comunicado ou ocorrência). Só a equipe; nada é publicado aqui. */
    public function maxEscrever(Request $request, \App\Services\Comunicacao\MaxEscreveService $max): JsonResponse
    {
        $p = $this->perfil($request);
        if (!$p->ehEquipe()) {
            return response()->json(['error' => 'Disponível para a equipe da escola.'], 403);
        }
        $tipo = in_array($request->input('tipo'), \App\Services\Comunicacao\MaxEscreveService::TIPOS, true) ? $request->input('tipo') : 'comunicado';
        try {
            return $this->resposta($max->escrever($p, $tipo, (string) $request->input('titulo', ''), (string) $request->input('texto', ''),
                (array) $request->input('contexto', []), $request->input('ajuste')));
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'O Max não conseguiu escrever agora. Tente de novo em instantes.'], 502);
        }
    }

    public function muralOpcoes(Request $request): JsonResponse
    {
        $p = $this->perfil($request);
        $turmas = DB::table('turmas')->where('status', 'Ativa')
            ->when(!$p->gestor, fn ($q) => $q->whereIn('id', $p->turmasProfessor ?: [0]))
            ->orderBy('ano_letivo')->orderBy('nome_turma')->get(['id', 'nome_turma', 'ano_letivo'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'nome' => $t->nome_turma, 'ano' => (int) $t->ano_letivo])->values();
        // Turmas de mais de um ano letivo (ex.: o próximo já criado): o nome leva o ano.
        if ($turmas->pluck('ano')->unique()->count() > 1) {
            $turmas = $turmas->map(fn ($t) => ['nome' => $t['nome'] . ' · ' . $t['ano']] + $t);
        }
        $turmas = $turmas->all();
        $publicos = $p->gestor ? MuralService::PUBLICOS : ($p->professorId ? ['turmas', 'equipe'] : []);
        return $this->resposta([
            'pode_publicar' => $p->ehEquipe(), 'pode_fixar' => $p->gestor, 'publicos' => $publicos,
            'turmas' => $turmas, 'reacoes' => MuralService::REACOES,
            'perfil' => ['gestor' => $p->gestor, 'professor' => $p->professorId !== null, 'familia' => $p->ehFamilia(), 'usuario_id' => $p->usuarioId],
        ]);
    }

    public function muralSalvar(Request $request, ?int $id = null): JsonResponse
    {
        $dados = $request->except('anexo');
        if (is_string($dados['turmas'] ?? null)) {
            $dados['turmas'] = json_decode($dados['turmas'], true) ?: array_filter(explode(',', $dados['turmas']));
        }
        return $this->resposta($this->mural->salvar($this->perfil($request), $dados, $request->file('anexo'), $id), $id ? 200 : 201);
    }

    public function muralExcluir(Request $request, int $id): JsonResponse
    {
        $erro = $this->mural->excluir($id, $this->perfil($request));
        return $erro ? response()->json(['error' => $erro], 422) : response()->json(['success' => true]);
    }

    public function muralLido(Request $request, int $id): JsonResponse
    {
        return $this->resposta($this->mural->marcarLido($id, $this->perfil($request), $request->boolean('ciente')));
    }

    public function muralTodosLidos(Request $request): JsonResponse
    {
        return $this->resposta(['marcados' => $this->mural->marcarTodosLidos($this->perfil($request))]);
    }

    public function muralReagir(Request $request, int $id): JsonResponse
    {
        return $this->resposta($this->mural->reagir($id, (string) $request->input('emoji', ''), $this->perfil($request)));
    }

    public function muralOcultar(Request $request, int $id): JsonResponse
    {
        return $this->resposta($this->mural->ocultar($id, $request->boolean('oculto', true), $this->perfil($request)));
    }

    public function muralConfirmacoes(Request $request, int $id): JsonResponse
    {
        return $this->resposta($this->mural->confirmacoes($id, $this->perfil($request)));
    }

    // ── Ocorrências ────────────────────────────────────────────────────────

    public function ocorrencias(Request $request): JsonResponse
    {
        return $this->resposta($this->ocorrencias->listar($this->perfil($request), $request->only(['aluno_id', 'turma_id', 'nivel', 'tipo', 'pendentes'])));
    }

    public function ocorrenciaSalvar(Request $request, ?int $id = null): JsonResponse
    {
        return $this->resposta($this->ocorrencias->salvar($this->perfil($request), $request->all(), $id), $id ? 200 : 201);
    }

    public function ocorrenciaExcluir(Request $request, int $id): JsonResponse
    {
        $erro = $this->ocorrencias->excluir($id, $this->perfil($request));
        return $erro ? response()->json(['error' => $erro], 422) : response()->json(['success' => true]);
    }

    public function ocorrenciaCiente(Request $request, int $id): JsonResponse
    {
        return $this->resposta($this->ocorrencias->ciente($id, $this->perfil($request)));
    }

    public function alunos(Request $request): JsonResponse
    {
        return $this->resposta($this->ocorrencias->alunosDaEquipe($this->perfil($request), $request->query('q')));
    }

    // ── Recados ────────────────────────────────────────────────────────────

    public function recados(Request $request): JsonResponse
    {
        return $this->resposta($this->recados->conversas($this->perfil($request), (string) $request->query('filtro', 'todas')));
    }

    public function recadoContatos(Request $request): JsonResponse
    {
        return $this->resposta($this->recados->contatosFamilia($this->perfil($request)));
    }

    public function recadoIniciar(Request $request): JsonResponse
    {
        return $this->resposta($this->recados->iniciar($this->perfil($request), $request->all()), 201);
    }

    public function recadoAbrir(Request $request, int $id): JsonResponse
    {
        return $this->resposta($this->recados->abrir($id, $this->perfil($request)));
    }

    public function recadoResponder(Request $request, int $id): JsonResponse
    {
        return $this->resposta($this->recados->responder($id, (string) $request->input('texto', ''), $this->perfil($request)));
    }

    public function recadoEncerrar(Request $request, int $id): JsonResponse
    {
        return $this->resposta($this->recados->encerrar($id, $request->boolean('encerrada', true), $this->perfil($request)));
    }
}
