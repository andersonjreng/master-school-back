<?php

namespace App\Services\Planejamento;

use App\Services\Max\AnthropicClient;
use App\Services\Max\UsageLimiter;
use App\Services\Turmas\CalendarioEscolarService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Planejar com o Max": gera uma sequência de aulas para a turma/disciplina,
 * distribuída nos próximos dias letivos do calendário escolar. O Max só pode
 * usar habilidades da BNCC do catálogo oficial que valem para a turma (não
 * inventa código); usa como contexto o que já foi dado (registros de aula) e
 * quantos alunos estão com dificuldade. Devolve um rascunho — nada é salvo
 * até o professor revisar.
 */
class MaxPlanejamentoService
{
    private const MAX_AULAS = 8;

    public function __construct(
        private AnthropicClient $claude,
        private UsageLimiter $limiter,
        private CalendarioEscolarService $calendario,
        private PlanejamentoService $planejamento,
    ) {}

    /**
     * @return array{aulas: array, dias_sem_vaga: int, avisos: string[]}
     */
    public function gerar(object $jwtUser, int $turmaId, int $disciplinaId, string $conteudo, int $quantidade, string $aPartirDe, ?string $observacoes): array
    {
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $limite = $this->limiter->verificar($usuarioId, in_array('Professor', $funcoes, true) ? 'Professor' : 'Administrador');
        if (!($limite['permitido'] ?? true)) {
            throw new \RuntimeException('Você atingiu o limite mensal de uso do Max.');
        }

        $quantidade = max(1, min(self::MAX_AULAS, $quantidade));
        $turma = DB::table('turmas')->where('id', $turmaId)->first(['id', 'nome_turma', 'ano_letivo', 'turno', 'sistema_avaliacao_id', 'etapa_bncc']);
        $disciplina = (string) DB::table('disciplinas')->where('id', $disciplinaId)->value('nome_disciplina');

        // Próximos dias letivos sem plano desta disciplina.
        $datas = $this->proximosDiasLetivos($turma, $disciplinaId, $aPartirDe, $quantidade);
        $avisos = [];
        if (count($datas) < $quantidade) {
            $avisos[] = 'Só há ' . count($datas) . ' dia(s) letivo(s) livre(s) até o fim do ano letivo para esta disciplina.';
        }
        if (!$datas) {
            return ['aulas' => [], 'avisos' => $avisos];
        }

        $bncc = $this->planejamento->habilidadesDaTurma($turmaId, $disciplinaId);
        $candidatas = $bncc['habilidades'];
        if (!$candidatas) {
            $avisos[] = $bncc['etapa'] === 'EF' && !$bncc['componente']
                ? "A disciplina \"$disciplina\" não é um componente da BNCC — o plano não terá códigos de habilidade."
                : 'Não encontrei habilidades da BNCC para esta turma — confira a etapa/ano da turma.';
        }

        $mensagem = $this->mensagem($turma, $disciplina, $bncc, $candidatas, $conteudo, $datas, $observacoes, $turmaId, $disciplinaId);
        $r = $this->claude->gerarTexto($this->sistema(), $mensagem, min(8000, 900 * count($datas) + 600), 120);
        $this->limiter->registrar($usuarioId, $r['tokens_entrada'], $r['tokens_saida']);

        $aulas = $this->interpretar($r['texto']);
        if (!$aulas) {
            throw new \RuntimeException('O Max não conseguiu montar o plano desta vez. Tente de novo, talvez com um conteúdo mais específico.');
        }

        $validas = array_column($candidatas, null, 'codigo');
        $saida = [];
        foreach (array_slice($aulas, 0, count($datas)) as $i => $a) {
            $codigos = array_values(array_filter((array) ($a['habilidades'] ?? []), fn ($c) => isset($validas[strtoupper(trim((string) $c))])));
            $codigos = array_map(fn ($c) => strtoupper(trim((string) $c)), $codigos);
            $saida[] = [
                'data' => $datas[$i],
                'tema' => mb_substr(trim((string) ($a['tema'] ?? 'Aula ' . ($i + 1))), 0, 200),
                'objetivos' => $this->lista($a['objetivos'] ?? null),
                'habilidades' => array_map(fn ($c) => $validas[$c], array_values(array_unique($codigos))),
                'desenvolvimento' => $this->lista($a['desenvolvimento'] ?? null, true),
                'recursos' => $this->lista($a['recursos'] ?? null),
                'avaliacao' => trim((string) ($a['avaliacao'] ?? '')) ?: null,
            ];
        }

        return ['aulas' => $saida, 'avisos' => $avisos];
    }

    /** @return string[] próximos dias letivos (a partir de $de) sem plano da disciplina */
    private function proximosDiasLetivos(object $turma, int $disciplinaId, string $de, int $n): array
    {
        $mapa = $this->calendario->mapa((int) $turma->ano_letivo);
        $ocupados = DB::table('planos_aula')->where('turma_id', $turma->id)->where('disciplina_id', $disciplinaId)
            ->where('data', '>=', $de)->pluck('data')->map(fn ($d) => substr((string) $d, 0, 10))->flip()->all();
        $fimAno = Carbon::create((int) $turma->ano_letivo, 12, 31);
        $datas = [];
        for ($d = Carbon::parse($de); $d->lte($fimAno) && count($datas) < $n; $d->addDay()) {
            $dia = $d->toDateString();
            if ($this->calendario->ehLetivo($dia, $mapa) && !isset($ocupados[$dia])) {
                $datas[] = $dia;
            }
        }
        return $datas;
    }

    private function mensagem(object $turma, string $disciplina, array $bncc, array $candidatas, string $conteudo, array $datas, ?string $obs, int $turmaId, int $disciplinaId): string
    {
        $etapa = $bncc['etapa'] === 'EI'
            ? 'Educação Infantil — ' . ($turma->etapa_bncc ?: 'grupo etário não informado')
            : 'Ensino Fundamental — ' . ($bncc['ano'] ? $bncc['ano'] . 'º ano' : 'ano não identificado');

        // O que já foi dado (para continuar a sequência, sem repetir).
        $dados = DB::table('registros_aula')->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)
            ->orderByDesc('data')->limit(8)->get(['data', 'conteudo'])
            ->map(fn ($r) => '- ' . Carbon::parse($r->data)->format('d/m') . ': ' . mb_substr(preg_replace('/\s+/', ' ', (string) $r->conteudo), 0, 160))
            ->reverse()->implode("\n");

        // Contexto da turma (só números, sem nomes de alunos).
        $roster = DB::table('turma_aluno as ta')->join('alunos as a', 'a.id', '=', 'ta.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('ta.turma_id', $turmaId)->where('u.ativo', 1)->pluck('ta.aluno_id')->all();
        $baixaFreq = $roster ? DB::table('frequencia')->whereIn('aluno_id', $roster)->where('data', '>=', now()->subDays(60)->toDateString())
            ->groupBy('aluno_id')->havingRaw('SUM(presente = 1) / COUNT(*) < 0.75')->select('aluno_id')->get()->count() : 0;

        $lista = $candidatas
            ? implode("\n", array_map(fn ($h) => "{$h['codigo']}: {$h['texto']}" . ($h['objeto_conhecimento'] ? " [{$h['objeto_conhecimento']}]" : ''), $candidatas))
            : '(nenhuma — não use códigos)';

        $quando = implode(', ', array_map(fn ($d) => Carbon::parse($d)->locale('pt_BR')->isoFormat('ddd DD/MM'), $datas));

        return "Turma: {$turma->nome_turma} ({$etapa}), turno {$turma->turno}, " . count($roster) . " alunos"
            . ($baixaFreq ? ", $baixaFreq com frequência abaixo de 75% nos últimos 60 dias" : '') . ".\n"
            . "Disciplina: $disciplina\n"
            . "Conteúdo pedido pelo professor: $conteudo\n"
            . ($obs ? "Observações do professor: $obs\n" : '')
            . 'Monte exatamente ' . count($datas) . " aula(s), uma para cada data: $quando.\n\n"
            . "Últimas aulas registradas nesta disciplina (continue a sequência, sem repetir):\n" . ($dados ?: '(nenhuma ainda)') . "\n\n"
            . "Habilidades/objetivos da BNCC permitidos (use SOMENTE códigos desta lista):\n$lista";
    }

    private function sistema(): string
    {
        return <<<'TXT'
Você é o Max, assistente pedagógico de uma escola brasileira. Monte planos de aula práticos, na linguagem do professor, alinhados à BNCC.

Regras:
- Responda APENAS com JSON válido, sem texto antes ou depois, no formato:
  {"aulas":[{"tema":"...","objetivos":["..."],"habilidades":["CÓDIGO"],"desenvolvimento":["passo 1","passo 2"],"recursos":["..."],"avaliacao":"..."}]}
- Uma aula por data, na ordem, formando uma sequência didática progressiva (introdução → prática → consolidação/avaliação).
- "habilidades": de 1 a 3 códigos, SOMENTE da lista fornecida. Se a lista estiver vazia, use [].
- "desenvolvimento": 3 a 6 passos curtos e concretos (o que o professor faz e o que os alunos fazem), com tempo aproximado quando ajudar.
- Adeque à faixa etária. Na Educação Infantil, use brincadeiras, interações e experiências, nada de "prova".
- Se houver alunos com frequência baixa, inclua uma retomada breve no início das aulas.
- "avaliacao": como o professor verifica a aprendizagem naquela aula (observação, atividade, tarefa) — curto.
- Não invente dados da turma nem nomes de alunos.
- Precisão do conteúdo: use só fatos que você tem certeza que estão corretos (datas, nomes, povos, lugares, fórmulas). Na dúvida, prefira uma descrição geral a um exemplo específico. Exemplos devem ser do contexto brasileiro quando o tema for o Brasil.
TXT;
    }

    /** @return array[] aulas do JSON do modelo (tolerante a cercas ```json) */
    private function interpretar(string $texto): array
    {
        $t = trim($texto);
        $t = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $t);
        $ini = strpos($t, '{');
        $fim = strrpos($t, '}');
        if ($ini === false || $fim === false) {
            return [];
        }
        $json = json_decode(substr($t, $ini, $fim - $ini + 1), true);
        return is_array($json['aulas'] ?? null) ? array_values(array_filter($json['aulas'], 'is_array')) : [];
    }

    private function lista(mixed $v, bool $numerada = false): ?string
    {
        if (is_string($v)) {
            return trim($v) ?: null;
        }
        if (!is_array($v) || !$v) {
            return null;
        }
        $itens = array_values(array_filter(array_map(fn ($x) => trim((string) $x), $v)));
        return implode("\n", array_map(fn ($x, $i) => ($numerada ? ($i + 1) . '. ' : '• ') . $x, $itens, array_keys($itens))) ?: null;
    }
}
