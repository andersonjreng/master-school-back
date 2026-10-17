<?php

namespace App\Services\Planejamento;

use App\Services\Turmas\CalendarioEscolarService;
use App\Services\Turmas\TurmaSituacaoService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Planejamento de aulas: uma aula planejada por turma/disciplina/data, nos
 * dias letivos do calendário escolar, com habilidades da BNCC. Quando a aula
 * é dada, vira registro de aula (registros_aula) com um clique.
 *
 * Aprovação (parâmetro planejamento_aprovacao): desligada, o plano nasce
 * "nao_requer"; ligada, nasce "rascunho" → professor envia ("pendente") →
 * direção aprova ("aprovado") ou devolve com comentário ("devolvido").
 * Editar um plano já enviado/aprovado volta para "rascunho".
 */
class PlanejamentoService
{
    /** Grupos etários da Educação Infantil (turmas.etapa_bncc) → grupo da BNCC. */
    private const GRUPOS_EI = ['bebes' => 1, 'criancas bem pequenas' => 2, 'criancas pequenas' => 3];

    public function __construct(
        private CalendarioEscolarService $calendario,
        private TurmaSituacaoService $situacao,
    ) {}

    public function aprovacaoAtiva(): bool
    {
        return DB::table('configuracoes_escola')->where('chave', 'planejamento_aprovacao')->value('valor') === '1';
    }

    public function definirAprovacao(bool $ativa): void
    {
        DB::table('configuracoes_escola')->updateOrInsert(
            ['chave' => 'planejamento_aprovacao'],
            ['valor' => $ativa ? '1' : '0', 'descricao' => 'Planos de aula passam pela aprovação da direção/coordenação (1 = sim, 0 = não)'],
        );
    }

    /** Turmas e disciplinas que o usuário pode planejar (professor: as dele). */
    public function opcoes(?int $professorId): array
    {
        $vinculos = DB::table('turma_professor_disciplina as tpd')
            ->join('turmas as t', 't.id', '=', 'tpd.turma_id')
            ->join('disciplinas as d', 'd.id', '=', 'tpd.disciplina_id')
            ->where('t.status', 'Ativa')
            ->when($professorId, fn ($q) => $q->where('tpd.professor_id', $professorId))
            ->orderBy('t.ano_letivo')->orderBy('t.nome_turma')->orderBy('d.nome_disciplina')
            ->get(['t.id as turma_id', 't.nome_turma', 't.ano_letivo', 't.turno', 't.sistema_avaliacao_id', 'd.id as disciplina_id', 'd.nome_disciplina']);

        $turmas = $vinculos->groupBy('turma_id')->map(function (Collection $linhas) {
            $t = $linhas->first();
            return [
                'turma_id' => (int) $t->turma_id, 'nome_turma' => $t->nome_turma, 'ano_letivo' => (int) $t->ano_letivo,
                'turno' => $t->turno, 'bncc' => (int) $t->sistema_avaliacao_id === 3,
                'disciplinas' => $linhas->unique('disciplina_id')->map(fn ($l) => ['id' => (int) $l->disciplina_id, 'nome' => $l->nome_disciplina])->values()->all(),
            ];
        })->values()->all();

        return ['turmas' => $turmas, 'aprovacao' => $this->aprovacaoAtiva()];
    }

    /** Dias (com status do calendário), planos e registros de aula no intervalo. */
    public function periodo(int $turmaId, string $de, string $ate, ?int $professorId, ?int $disciplinaId): array
    {
        $turma = DB::table('turmas')->where('id', $turmaId)->first(['id', 'nome_turma', 'ano_letivo', 'status']);
        $mapa = $this->calendario->mapa((int) $turma->ano_letivo);

        $dias = [];
        for ($d = Carbon::parse($de); $d->lte(Carbon::parse($ate)); $d->addDay()) {
            $dia = $d->toDateString();
            $status = $this->calendario->classificar($dia, $mapa);
            if ($status === 'fim_de_semana') {
                continue; // fim de semana comum não aparece na grade
            }
            $dias[] = [
                'data'   => $dia,
                'status' => $status,
                'letivo' => in_array($status, ['letivo', 'sabado_letivo'], true),
                'motivo' => $this->calendario->motivo($dia, $mapa),
            ];
        }

        $discIds = $this->disciplinasPermitidas($turmaId, $professorId);
        if ($disciplinaId) {
            $discIds = array_values(array_intersect($discIds, [$disciplinaId]));
        }

        $planos = DB::table('planos_aula as p')
            ->join('disciplinas as d', 'd.id', '=', 'p.disciplina_id')
            ->leftJoin('professores as pr', 'pr.id', '=', 'p.professor_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'pr.usuario_id')
            ->where('p.turma_id', $turmaId)->whereBetween('p.data', [$de, $ate])
            ->whereIn('p.disciplina_id', $discIds ?: [0])
            ->orderBy('p.data')->orderBy('d.nome_disciplina')->orderBy('p.id')
            ->get(['p.*', 'd.nome_disciplina', 'u.nome_completo as professor']);

        $registros = DB::table('registros_aula')
            ->where('turma_id', $turmaId)->whereBetween('data', [$de, $ate])
            ->whereIn('disciplina_id', $discIds ?: [0])
            ->get(['id', 'data', 'disciplina_id', 'conteudo']);

        return [
            'turma' => ['id' => (int) $turma->id, 'nome' => $turma->nome_turma, 'ano_letivo' => (int) $turma->ano_letivo, 'editavel' => $turma->status === 'Ativa'],
            'dias' => $dias,
            'planos' => $planos->map(fn ($p) => $this->formatar($p))->values()->all(),
            'registros' => $registros->map(fn ($r) => [
                'id' => (int) $r->id, 'data' => substr((string) $r->data, 0, 10), 'disciplina_id' => (int) $r->disciplina_id, 'conteudo' => $r->conteudo,
            ])->values()->all(),
            'aprovacao' => $this->aprovacaoAtiva(),
            'pendentes_aprovacao' => $professorId ? 0 : DB::table('planos_aula')->where('turma_id', $turmaId)->where('aprovacao', 'pendente')->count(),
        ];
    }

    /** Fila de aprovação da direção (todas as turmas). */
    public function pendentes(): array
    {
        return DB::table('planos_aula as p')
            ->join('disciplinas as d', 'd.id', '=', 'p.disciplina_id')
            ->join('turmas as t', 't.id', '=', 'p.turma_id')
            ->leftJoin('professores as pr', 'pr.id', '=', 'p.professor_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'pr.usuario_id')
            ->where('p.aprovacao', 'pendente')
            ->orderBy('p.data')->limit(200)
            ->get(['p.*', 'd.nome_disciplina', 't.nome_turma', 'u.nome_completo as professor'])
            ->map(fn ($p) => $this->formatar($p) + ['turma' => $p->nome_turma])->values()->all();
    }

    /** Cria ou atualiza um plano. @return array|string plano salvo, ou erro */
    public function salvar(array $dados, ?int $id, ?int $professorId, int $usuarioId, bool $gestor): array|string
    {
        $turmaId = (int) ($dados['turma_id'] ?? 0);
        $disciplinaId = (int) ($dados['disciplina_id'] ?? 0);
        $data = (string) ($dados['data'] ?? '');
        $tema = trim((string) ($dados['tema'] ?? ''));

        if (!$turmaId || !$disciplinaId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $tema === '') {
            return 'Informe turma, disciplina, data e o tema da aula.';
        }
        if (!in_array($disciplinaId, $this->disciplinasPermitidas($turmaId, $professorId), true)) {
            return 'Você não leciona esta disciplina nesta turma.';
        }
        if (!$this->situacao->editavel($turmaId)) {
            return 'A turma está encerrada e não aceita alterações.';
        }

        $atual = $id ? DB::table('planos_aula')->where('id', $id)->first() : null;
        if ($id && !$atual) {
            return 'Plano não encontrado.';
        }
        if ($atual && $professorId && (int) $atual->professor_id !== $professorId) {
            return 'Este plano é de outro professor.';
        }

        $habilidades = array_values(array_unique(array_filter(array_map('strval', (array) ($dados['habilidades'] ?? [])))));
        $validas = $habilidades ? DB::table('bncc_habilidades')->whereIn('codigo', $habilidades)->pluck('codigo')->all() : [];

        $campos = [
            'turma_id' => $turmaId, 'disciplina_id' => $disciplinaId, 'data' => $data,
            'tema' => mb_substr($tema, 0, 200),
            'objetivos' => $this->texto($dados['objetivos'] ?? null),
            'habilidades' => json_encode(array_values(array_intersect($habilidades, $validas))),
            'desenvolvimento' => $this->texto($dados['desenvolvimento'] ?? null),
            'recursos' => $this->texto($dados['recursos'] ?? null),
            'avaliacao' => $this->texto($dados['avaliacao'] ?? null),
            'observacoes' => $this->texto($dados['observacoes'] ?? null),
        ];

        // Aprovação: editar volta para rascunho (a direção revisa a versão nova).
        $aprovacao = $this->aprovacaoAtiva();
        if (!$atual) {
            $campos += [
                'professor_id' => $professorId ?? $this->professorDaDisciplina($turmaId, $disciplinaId),
                'aprovacao' => $aprovacao ? 'rascunho' : 'nao_requer',
                'gerado_max' => !empty($dados['gerado_max']) ? 1 : 0,
                'criado_por' => $usuarioId,
            ];
            $id = DB::table('planos_aula')->insertGetId($campos);
        } else {
            if ($aprovacao && !$gestor && in_array($atual->aprovacao, ['pendente', 'aprovado', 'devolvido'], true)) {
                $campos['aprovacao'] = 'rascunho';
            }
            DB::table('planos_aula')->where('id', $id)->update($campos);
        }

        return $this->buscar($id);
    }

    public function excluir(int $id, ?int $professorId): ?string
    {
        $p = DB::table('planos_aula')->where('id', $id)->first();
        if (!$p) {
            return 'Plano não encontrado.';
        }
        if ($professorId && (int) $p->professor_id !== $professorId) {
            return 'Este plano é de outro professor.';
        }
        DB::table('planos_aula')->where('id', $id)->delete();
        return null;
    }

    /** Professor envia para aprovação. */
    public function enviar(int $id, ?int $professorId): array|string
    {
        $p = DB::table('planos_aula')->where('id', $id)->first();
        if (!$p || ($professorId && (int) $p->professor_id !== $professorId)) {
            return 'Plano não encontrado.';
        }
        if (!$this->aprovacaoAtiva()) {
            return 'A aprovação de planos está desligada nas Parametrizações.';
        }
        DB::table('planos_aula')->where('id', $id)->update(['aprovacao' => 'pendente']);
        return $this->buscar($id);
    }

    /** Direção aprova ou devolve com comentário. */
    public function decidir(int $id, bool $aprovar, ?string $comentario, int $usuarioId): array|string
    {
        $p = DB::table('planos_aula')->where('id', $id)->first();
        if (!$p) {
            return 'Plano não encontrado.';
        }
        if (!$aprovar && trim((string) $comentario) === '') {
            return 'Escreva um comentário para o professor ao devolver o plano.';
        }
        DB::table('planos_aula')->where('id', $id)->update([
            'aprovacao' => $aprovar ? 'aprovado' : 'devolvido',
            'comentario_aprovacao' => $this->texto($comentario),
            'aprovado_por' => $usuarioId,
            'aprovado_em' => now(),
        ]);
        return $this->buscar($id);
    }

    /**
     * Aula dada: cria o registro de aula a partir do plano (ou liga a um
     * registro que já existe para a mesma turma/data/disciplina).
     */
    public function marcarDada(int $id, ?int $professorId, bool $confirmarDiaNaoLetivo): array|string
    {
        $p = DB::table('planos_aula as p')->join('disciplinas as d', 'd.id', '=', 'p.disciplina_id')
            ->where('p.id', $id)->first(['p.*', 'd.nome_disciplina']);
        if (!$p || ($professorId && (int) $p->professor_id !== $professorId)) {
            return 'Plano não encontrado.';
        }
        if (!$this->situacao->editavel((int) $p->turma_id)) {
            return 'A turma está encerrada e não aceita alterações.';
        }
        $data = substr((string) $p->data, 0, 10);
        if ($data > now()->toDateString()) {
            return 'Esta aula ainda não aconteceu — marque como dada no dia.';
        }
        if (!$confirmarDiaNaoLetivo && ($aviso = $this->calendario->avisoDiaNaoLetivo((int) $p->turma_id, $data))) {
            return 'dia_nao_letivo:' . $aviso['message'];
        }

        $existente = DB::table('registros_aula')->where('turma_id', $p->turma_id)->where('data', $data)
            ->where('disciplina_id', $p->disciplina_id)
            ->when($p->professor_id, fn ($q) => $q->where('professor_id', $p->professor_id))
            ->value('id');

        $registroId = $existente ?: DB::table('registros_aula')->insertGetId([
            'turma_id' => $p->turma_id, 'professor_id' => $p->professor_id, 'disciplina_id' => $p->disciplina_id,
            'disciplina' => $p->nome_disciplina, 'data' => $data,
            'conteudo' => $this->conteudoRegistro($p), 'numero_aulas' => 1,
            'observacoes' => $p->observacoes,
        ]);

        DB::table('planos_aula')->where('id', $id)->update(['status' => 'dada', 'registro_aula_id' => $registroId]);
        return $this->buscar($id) + ['registro_existente' => (bool) $existente];
    }

    // ── BNCC ───────────────────────────────────────────────────────────────

    /**
     * Habilidades da BNCC que valem para a turma/disciplina (EF: componente +
     * ano; EI: grupo etário, todos os campos de experiência).
     *
     * @return array{etapa: ?string, ano: ?int, componente: ?string, habilidades: array}
     */
    public function habilidadesDaTurma(int $turmaId, int $disciplinaId, ?string $busca = null, int $limite = 400): array
    {
        $turma = DB::table('turmas')->where('id', $turmaId)->first(['nome_turma', 'sistema_avaliacao_id', 'etapa_bncc']);
        $disciplina = (string) DB::table('disciplinas')->where('id', $disciplinaId)->value('nome_disciplina');
        if (!$turma) {
            return ['etapa' => null, 'ano' => null, 'componente' => null, 'habilidades' => []];
        }

        $q = DB::table('bncc_habilidades');
        $etapa = null;
        $ano = null;
        $componente = null;
        if ((int) $turma->sistema_avaliacao_id === 3) {
            $etapa = 'EI';
            $ano = self::GRUPOS_EI[$this->normalizar((string) $turma->etapa_bncc)] ?? null;
            $q->where('etapa', 'EI')->when($ano, fn ($x) => $x->where('ano_min', $ano));
        } else {
            $etapa = 'EF';
            $ano = preg_match('/(\d+)\s*[ºª°o]?\s*ano/iu', (string) $turma->nome_turma, $m) ? (int) $m[1] : null;
            $componente = DB::table('bncc_habilidades')->where('etapa', 'EF')->distinct()->pluck('componente')
                ->first(fn ($c) => $this->normalizar($c) === $this->normalizar($disciplina));
            if (!$componente) {
                return ['etapa' => $etapa, 'ano' => $ano, 'componente' => null, 'habilidades' => []];
            }
            $q->where('etapa', 'EF')->where('componente', $componente)
                ->when($ano, fn ($x) => $x->where('ano_min', '<=', $ano)->where('ano_max', '>=', $ano));
        }

        // Busca por palavras (todas precisam aparecer), tolerante a plural/terminação:
        // "fração" encontra "frações", "multiplicação" encontra "multiplicações".
        foreach (preg_split('/\s+/u', trim((string) $busca)) ?: [] as $palavra) {
            if (mb_strlen($palavra) < 2) {
                continue;
            }
            $raiz = mb_strlen($palavra) > 4 ? preg_replace('/(ões|ães|ãos|ão|ais|eis|es|s)$/u', '', mb_strtolower($palavra)) : $palavra;
            $termo = '%' . $raiz . '%';
            $q->where(fn ($x) => $x->where('codigo', 'like', $termo)->orWhere('texto', 'like', $termo)
                ->orWhere('objeto_conhecimento', 'like', $termo)->orWhere('unidade_tematica', 'like', $termo));
        }

        $lista = $q->orderBy('codigo')->limit($limite)
            ->get(['codigo', 'componente', 'unidade_tematica', 'objeto_conhecimento', 'texto'])
            ->map(fn ($h) => (array) $h)->all();

        return ['etapa' => $etapa, 'ano' => $ano, 'componente' => $componente ?? 'Campos de experiência', 'habilidades' => $lista];
    }

    /** @return array<string, array> código => habilidade */
    public function habilidadesPorCodigo(array $codigos): array
    {
        if (!$codigos) {
            return [];
        }
        return DB::table('bncc_habilidades')->whereIn('codigo', $codigos)
            ->get(['codigo', 'componente', 'unidade_tematica', 'objeto_conhecimento', 'texto'])
            ->keyBy('codigo')->map(fn ($h) => (array) $h)->all();
    }

    // ── Auxiliares ─────────────────────────────────────────────────────────

    /** @return int[] disciplinas da turma que o usuário pode planejar */
    public function disciplinasPermitidas(int $turmaId, ?int $professorId): array
    {
        return DB::table('turma_professor_disciplina')->where('turma_id', $turmaId)
            ->when($professorId, fn ($q) => $q->where('professor_id', $professorId))
            ->distinct()->pluck('disciplina_id')->map(fn ($id) => (int) $id)->all();
    }

    public function buscar(int $id): array
    {
        $p = DB::table('planos_aula as p')
            ->join('disciplinas as d', 'd.id', '=', 'p.disciplina_id')
            ->leftJoin('professores as pr', 'pr.id', '=', 'p.professor_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'pr.usuario_id')
            ->where('p.id', $id)->first(['p.*', 'd.nome_disciplina', 'u.nome_completo as professor']);
        return $this->formatar($p);
    }

    private function formatar(object $p): array
    {
        $codigos = json_decode((string) ($p->habilidades ?? '[]'), true) ?: [];
        $hab = $this->habilidadesPorCodigo($codigos);
        return [
            'id' => (int) $p->id, 'turma_id' => (int) $p->turma_id, 'disciplina_id' => (int) $p->disciplina_id,
            'disciplina' => $p->nome_disciplina, 'professor_id' => $p->professor_id ? (int) $p->professor_id : null,
            'professor' => $p->professor ?? null, 'data' => substr((string) $p->data, 0, 10),
            'tema' => $p->tema, 'objetivos' => $p->objetivos,
            'habilidades' => array_map(fn ($c) => $hab[$c] ?? ['codigo' => $c, 'texto' => ''], $codigos),
            'desenvolvimento' => $p->desenvolvimento, 'recursos' => $p->recursos, 'avaliacao' => $p->avaliacao,
            'observacoes' => $p->observacoes, 'status' => $p->status, 'aprovacao' => $p->aprovacao,
            'comentario_aprovacao' => $p->comentario_aprovacao, 'aprovado_em' => $p->aprovado_em,
            'registro_aula_id' => $p->registro_aula_id ? (int) $p->registro_aula_id : null,
            'gerado_max' => (bool) $p->gerado_max,
        ];
    }

    private function professorDaDisciplina(int $turmaId, int $disciplinaId): ?int
    {
        $id = DB::table('turma_professor_disciplina')->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)->value('professor_id');
        return $id ? (int) $id : null;
    }

    private function conteudoRegistro(object $p): string
    {
        $partes = [$p->tema];
        if ($p->objetivos) {
            $partes[] = 'Objetivos: ' . $p->objetivos;
        }
        $codigos = json_decode((string) ($p->habilidades ?? '[]'), true) ?: [];
        if ($codigos) {
            $partes[] = 'BNCC: ' . implode(', ', $codigos);
        }
        if ($p->desenvolvimento) {
            $partes[] = $p->desenvolvimento;
        }
        return implode("\n\n", $partes);
    }

    private function texto(mixed $v): ?string
    {
        $t = trim((string) ($v ?? ''));
        return $t === '' ? null : $t;
    }

    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    }
}
