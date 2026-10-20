<?php

namespace App\Services\Comunicacao;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recados: conversa privada sobre um aluno entre a família e um professor
 * (ou a secretaria, quando escola_usuario_id é NULL — atendida pela direção).
 *
 * - Família inicia com os professores do filho ou com a secretaria.
 * - Professor inicia com as famílias dos alunos dele (um aluno ou a turma
 *   inteira — vira uma conversa por aluno, cada família responde no privado).
 * - Direção: participa das conversas da secretaria e tem acesso de
 *   auditoria (somente leitura) às conversas dos professores.
 */
class RecadoService
{
    // ── Lista ──────────────────────────────────────────────────────────────

    public function conversas(PerfilComunicacao $p, string $filtro = 'todas'): array
    {
        $q = DB::table('recados_conversas as c')
            ->join('alunos as a', 'a.id', '=', 'c.aluno_id')->join('usuarios as ua', 'ua.id', '=', 'a.usuario_id')
            ->leftJoin('turma_aluno as ta', 'ta.aluno_id', '=', 'c.aluno_id')->leftJoin('turmas as t', 't.id', '=', 'ta.turma_id')
            ->leftJoin('usuarios as eu', 'eu.id', '=', 'c.escola_usuario_id')
            ->select('c.*', 'ua.nome_completo as aluno', 'a.foto_path', 't.nome_turma as turma', 'eu.nome_completo as escola_nome');

        $this->escopo($q, $p);
        if ($filtro === 'secretaria') {
            $q->whereNull('c.escola_usuario_id');
        }

        $lista = $q->orderByDesc('c.ultima_mensagem_em')->limit(300)->get()->unique('id')->values();
        if ($lista->isEmpty()) {
            return [];
        }
        $ultimas = DB::table('recados_mensagens as m')->whereIn('m.conversa_id', $lista->pluck('id'))
            ->whereIn('m.id', DB::table('recados_mensagens')->whereIn('conversa_id', $lista->pluck('id'))->groupBy('conversa_id')->selectRaw('MAX(id)'))
            ->get()->keyBy('conversa_id');
        $leituras = DB::table('recados_leituras')->where('usuario_id', $p->usuarioId)->whereIn('conversa_id', $lista->pluck('id'))->pluck('ultima_lida_id', 'conversa_id');
        $naoLidas = $this->naoLidasPorConversa($lista, $p, $leituras);

        $saida = $lista->map(function ($c) use ($p, $ultimas, $naoLidas) {
            $u = $ultimas->get($c->id);
            return [
                'id' => (int) $c->id, 'aluno_id' => (int) $c->aluno_id, 'aluno' => $c->aluno, 'turma' => $c->turma,
                'foto_url' => \App\Services\Alunos\FotoAlunoService::url($c->foto_path),
                'assunto' => $c->assunto,
                'com' => $c->escola_usuario_id ? $c->escola_nome : 'Secretaria',
                'secretaria' => $c->escola_usuario_id === null,
                'ultima' => $u ? ['texto' => mb_substr($u->texto, 0, 140), 'lado' => $u->lado, 'criado_em' => $u->criado_em] : null,
                'ultima_mensagem_em' => $c->ultima_mensagem_em,
                'nao_lidas' => $naoLidas[$c->id] ?? 0,
                'auditoria' => $this->papelNaConversa($c, $p) === 'auditoria',
                'encerrada' => (bool) $c->encerrada,
            ];
        });
        if ($filtro === 'nao_lidas') {
            $saida = $saida->filter(fn ($c) => $c['nao_lidas'] > 0);
        }
        return $saida->values()->all();
    }

    /** Total de mensagens não lidas do usuário (para o sino). */
    public function totalNaoLidas(PerfilComunicacao $p): int
    {
        return array_sum(array_column($this->conversas($p), 'nao_lidas'));
    }

    // ── Conversa ───────────────────────────────────────────────────────────

    public function abrir(int $id, PerfilComunicacao $p): array|string
    {
        $c = $this->conversa($id, $p);
        if (!$c) {
            return 'Conversa não encontrada.';
        }
        $papel = $this->papelNaConversa($c, $p);
        $msgs = DB::table('recados_mensagens as m')->leftJoin('usuarios as u', 'u.id', '=', 'm.autor_usuario_id')
            ->where('m.conversa_id', $id)->orderBy('m.id')->get(['m.*', 'u.nome_completo as autor']);

        // Até onde o outro lado já leu (para o "visto").
        $leituras = DB::table('recados_leituras')->where('conversa_id', $id)->get();
        $familiaUsuarios = $this->usuariosFamilia((int) $c->aluno_id);
        $lidoFamilia = (int) $leituras->whereIn('usuario_id', $familiaUsuarios)->max('ultima_lida_id');
        $lidoEscola = (int) $leituras->whereNotIn('usuario_id', $familiaUsuarios)->max('ultima_lida_id');

        // Quem abre marca como lida (auditoria não conta como leitura). Só grava
        // quando a leitura avança — o "visto" do pulso não pode mudar à toa.
        $jaLida = (int) $leituras->firstWhere('usuario_id', $p->usuarioId)?->ultima_lida_id;
        if ($papel !== 'auditoria' && $msgs->isNotEmpty() && $msgs->last()->id > $jaLida) {
            DB::table('recados_leituras')->updateOrInsert(['conversa_id' => $id, 'usuario_id' => $p->usuarioId], ['ultima_lida_id' => $msgs->last()->id, 'lido_em' => now()]);
        }

        $aluno = DB::table('alunos as a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')->where('a.id', $c->aluno_id)->first(['u.nome_completo', 'a.foto_path']);
        return [
            'id' => (int) $c->id, 'assunto' => $c->assunto, 'aluno_id' => (int) $c->aluno_id, 'aluno' => $aluno->nome_completo ?? '',
            'foto_url' => \App\Services\Alunos\FotoAlunoService::url($aluno->foto_path ?? null),
            'com' => $c->escola_usuario_id ? DB::table('usuarios')->where('id', $c->escola_usuario_id)->value('nome_completo') : 'Secretaria',
            'secretaria' => $c->escola_usuario_id === null,
            'meu_lado' => $papel === 'familia' ? 'familia' : 'escola',
            'auditoria' => $papel === 'auditoria',
            'encerrada' => (bool) $c->encerrada,
            'mensagens' => $msgs->map(fn ($m) => [
                'id' => (int) $m->id, 'lado' => $m->lado, 'autor' => $m->autor, 'texto' => $m->texto, 'criado_em' => $m->criado_em,
                'minha' => (int) $m->autor_usuario_id === $p->usuarioId,
                'vista' => $m->lado === 'escola' ? $lidoFamilia >= $m->id : $lidoEscola >= $m->id,
            ])->values()->all(),
        ];
    }

    public function responder(int $id, string $texto, PerfilComunicacao $p): array|string
    {
        $c = $this->conversa($id, $p);
        $texto = trim($texto);
        if (!$c) {
            return 'Conversa não encontrada.';
        }
        $papel = $this->papelNaConversa($c, $p);
        if ($papel === 'auditoria') {
            return 'Você está vendo esta conversa como auditoria — só o professor e a família escrevem nela.';
        }
        if ($c->encerrada) {
            return 'Esta conversa foi encerrada.';
        }
        if ($texto === '') {
            return 'Escreva a mensagem.';
        }
        $this->inserirMensagem($id, $p->usuarioId, $papel === 'familia' ? 'familia' : 'escola', $texto);
        return $this->abrir($id, $p);
    }

    public function encerrar(int $id, bool $encerrada, PerfilComunicacao $p): array|string
    {
        $c = $this->conversa($id, $p);
        if (!$c || $this->papelNaConversa($c, $p) !== 'escola') {
            return 'Somente a escola encerra conversas.';
        }
        DB::table('recados_conversas')->where('id', $id)->update(['encerrada' => $encerrada ? 1 : 0]);
        return $this->abrir($id, $p);
    }

    /**
     * Nova conversa.
     * Família: aluno_id + para (usuario_id do professor, ou null = secretaria).
     * Escola: aluno_ids[] ou turma_id (uma conversa por aluno).
     *
     * @return array|string {conversas: int[]} ou erro
     */
    public function iniciar(PerfilComunicacao $p, array $d): array|string
    {
        $assunto = mb_substr(trim((string) ($d['assunto'] ?? '')), 0, 150);
        $texto = trim((string) ($d['texto'] ?? ''));
        if ($assunto === '' || $texto === '') {
            return 'Escreva o assunto e a mensagem.';
        }

        if (($d['lado'] ?? null) === 'familia' || (!$p->ehEquipe() && $p->ehFamilia())) {
            $alunoId = (int) ($d['aluno_id'] ?? 0);
            if (!in_array($alunoId, $p->dependentes, true)) {
                return 'Escolha um dos seus dependentes.';
            }
            $para = !empty($d['para']) ? (int) $d['para'] : null;
            if ($para && !in_array($para, array_column($this->professoresDoAluno($alunoId), 'usuario_id'), true)) {
                return 'Escolha um professor do aluno ou a secretaria.';
            }
            $id = $this->criarConversa($alunoId, $para, $assunto, $p->usuarioId, 'familia', $texto);
            return ['conversas' => [$id]];
        }

        if (!$p->ehEquipe()) {
            return 'Sem permissão para iniciar conversas.';
        }
        $alunos = array_map('intval', (array) ($d['aluno_ids'] ?? []));
        if (!empty($d['turma_id'])) {
            $turmaId = (int) $d['turma_id'];
            if (!$p->gestor && !in_array($turmaId, $p->turmasProfessor, true)) {
                return 'Você só envia recados para as suas turmas.';
            }
            $alunos = DB::table('turma_aluno as ta')->join('alunos as a', 'a.id', '=', 'ta.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
                ->where('ta.turma_id', $turmaId)->where('u.ativo', 1)->pluck('ta.aluno_id')->map(fn ($x) => (int) $x)->all();
        }
        $alunos = array_values(array_unique($alunos));
        if (!$alunos) {
            return 'Escolha pelo menos um aluno.';
        }
        foreach ($alunos as $a) {
            if (!$p->equipeDoAluno($a)) {
                return 'Você só envia recados para famílias de alunos das suas turmas.';
            }
        }
        // Professor fala em nome próprio; a gestão (ou "como secretaria") fala pela secretaria.
        $escolaUsuario = $p->professorId && $p->lecionaPara($alunos[0]) && empty($d['como_secretaria']) ? $p->usuarioId : null;
        $ids = [];
        DB::transaction(function () use ($alunos, $escolaUsuario, $assunto, $p, $texto, &$ids) {
            foreach ($alunos as $a) {
                $ids[] = $this->criarConversa($a, $escolaUsuario, $assunto, $p->usuarioId, 'escola', $texto);
            }
        });
        return ['conversas' => $ids];
    }

    /** Com quem a família pode falar sobre cada dependente. */
    public function contatosFamilia(PerfilComunicacao $p): array
    {
        if (!$p->ehFamilia()) {
            return [];
        }
        return DB::table('alunos as a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')->whereIn('a.id', $p->dependentes ?: [0])
            ->orderBy('u.nome_completo')->get(['a.id', 'u.nome_completo'])
            ->map(fn ($a) => [
                'aluno_id' => (int) $a->id, 'aluno' => $a->nome_completo,
                'contatos' => array_merge([['usuario_id' => null, 'nome' => 'Secretaria / Direção', 'detalhe' => 'Assuntos gerais, financeiro, documentos']], $this->professoresDoAluno((int) $a->id)),
            ])->values()->all();
    }

    // ── Auxiliares ─────────────────────────────────────────────────────────

    private function escopo($q, PerfilComunicacao $p): void
    {
        if ($p->gestor) {
            return; // tudo (secretaria + auditoria)
        }
        $q->where(function ($x) use ($p) {
            if ($p->professorId) {
                $x->orWhere('c.escola_usuario_id', $p->usuarioId);
            }
            if ($p->ehFamilia()) {
                $x->orWhereIn('c.aluno_id', $p->dependentes ?: [0]);
            }
            if (!$p->professorId && !$p->ehFamilia()) {
                $x->whereRaw('1 = 0');
            }
        });
    }

    private function conversa(int $id, PerfilComunicacao $p): ?object
    {
        $q = DB::table('recados_conversas as c')->where('c.id', $id)->select('c.*');
        $this->escopo($q, $p);
        return $q->first();
    }

    /** familia | escola | auditoria */
    private function papelNaConversa(object $c, PerfilComunicacao $p): string
    {
        if (in_array((int) $c->aluno_id, $p->dependentes, true) && (int) ($c->escola_usuario_id ?? 0) !== $p->usuarioId) {
            return 'familia';
        }
        if ((int) ($c->escola_usuario_id ?? 0) === $p->usuarioId || ($c->escola_usuario_id === null && $p->gestor)) {
            return 'escola';
        }
        return 'auditoria';
    }

    /** @return array<int, int> conversa_id => mensagens não lidas (do outro lado) */
    private function naoLidasPorConversa(Collection $lista, PerfilComunicacao $p, $leituras): array
    {
        $porLado = ['familia' => [], 'escola' => []];
        foreach ($lista as $c) {
            $papel = $this->papelNaConversa($c, $p);
            if ($papel === 'auditoria') {
                continue;
            }
            $porLado[$papel][] = (int) $c->id;
        }
        $saida = [];
        foreach (['familia' => 'escola', 'escola' => 'familia'] as $meuLado => $outroLado) {
            if (!$porLado[$meuLado]) {
                continue;
            }
            $msgs = DB::table('recados_mensagens')->whereIn('conversa_id', $porLado[$meuLado])->where('lado', $outroLado)->get(['id', 'conversa_id']);
            foreach ($msgs as $m) {
                if ($m->id > (int) ($leituras[$m->conversa_id] ?? 0)) {
                    $saida[$m->conversa_id] = ($saida[$m->conversa_id] ?? 0) + 1;
                }
            }
        }
        return $saida;
    }

    private function criarConversa(int $alunoId, ?int $escolaUsuario, string $assunto, int $autor, string $lado, string $texto): int
    {
        $id = DB::table('recados_conversas')->insertGetId([
            'aluno_id' => $alunoId, 'escola_usuario_id' => $escolaUsuario, 'assunto' => $assunto, 'criado_por' => $autor,
        ]);
        $this->inserirMensagem($id, $autor, $lado, $texto);
        return $id;
    }

    private function inserirMensagem(int $conversaId, int $autor, string $lado, string $texto): void
    {
        $msgId = DB::table('recados_mensagens')->insertGetId([
            'conversa_id' => $conversaId, 'autor_usuario_id' => $autor, 'lado' => $lado, 'texto' => mb_substr($texto, 0, 5000),
        ]);
        DB::table('recados_conversas')->where('id', $conversaId)->update(['ultima_mensagem_em' => now()]);
        DB::table('recados_leituras')->updateOrInsert(['conversa_id' => $conversaId, 'usuario_id' => $autor], ['ultima_lida_id' => $msgId, 'lido_em' => now()]);
    }

    /** @return int[] usuários responsáveis do aluno */
    private function usuariosFamilia(int $alunoId): array
    {
        return DB::table('aluno_responsavel as ar')->join('responsaveis as r', 'r.id', '=', 'ar.responsavel_id')
            ->where('ar.aluno_id', $alunoId)->pluck('r.usuario_id')->map(fn ($x) => (int) $x)->all();
    }

    private function professoresDoAluno(int $alunoId): array
    {
        return DB::table('turma_aluno as ta')
            ->join('turma_professor_disciplina as tpd', 'tpd.turma_id', '=', 'ta.turma_id')
            ->join('professores as pr', 'pr.id', '=', 'tpd.professor_id')->join('usuarios as u', 'u.id', '=', 'pr.usuario_id')
            ->join('disciplinas as d', 'd.id', '=', 'tpd.disciplina_id')
            ->where('ta.aluno_id', $alunoId)->where('u.ativo', 1)
            ->groupBy('u.id', 'u.nome_completo')
            ->selectRaw('u.id AS usuario_id, u.nome_completo AS nome, GROUP_CONCAT(DISTINCT d.nome_disciplina ORDER BY d.nome_disciplina SEPARATOR ", ") AS detalhe')
            ->orderBy('u.nome_completo')->get()
            ->map(fn ($x) => ['usuario_id' => (int) $x->usuario_id, 'nome' => $x->nome, 'detalhe' => $x->detalhe])->values()->all();
    }
}
