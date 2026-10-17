<?php

namespace App\Services\Direcao;

use App\Services\Max\AnthropicClient;
use App\Services\Max\UsageLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * O Max dentro do Painel da Direção:
 *  - briefing do dia: 3 a 5 pontos gerados UMA vez por dia (guardado em
 *    max_briefings), desligável em Parametrizações → Assistente Max;
 *  - explicar: análise curta do recorte de dados que está na tela (conta no
 *    limite mensal do usuário, como qualquer pergunta ao Max).
 */
class MaxDirecaoService
{
    /** Haiku 4.5: US$ 1/MTok entrada, US$ 5/MTok saída. */
    private const CUSTO_ENTRADA = 0.000001;
    private const CUSTO_SAIDA = 0.000005;

    public function __construct(
        private AnthropicClient $claude,
        private PainelDirecaoService $painel,
        private UsageLimiter $limiter,
    ) {
    }

    public function briefingAtivo(): bool
    {
        return DB::table('configuracoes_escola')->where('chave', 'max_briefing_diario')->value('valor') !== '0';
    }

    public function definirBriefingAtivo(bool $ativo): void
    {
        DB::table('configuracoes_escola')->updateOrInsert(['chave' => 'max_briefing_diario'], ['valor' => $ativo ? '1' : '0']);
    }

    /** Uso do briefing no mês (para mostrar o custo em Parametrizações). */
    public function usoDoMes(): array
    {
        $r = DB::table('max_briefings')->where('data', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('COUNT(*) AS qtd, COALESCE(SUM(custo_estimado), 0) AS custo')->first();
        return ['briefings' => (int) $r->qtd, 'custo_usd' => round((float) $r->custo, 4)];
    }

    /** @return array{ativo: bool, data?: string, pontos?: array, gerado_em?: string, erro?: string} */
    public function briefing(int $ano): array
    {
        if (!$this->briefingAtivo()) {
            return ['ativo' => false];
        }
        $hoje = now()->toDateString();
        $existente = fn () => DB::table('max_briefings')->where('data', $hoje)->where('ano_letivo', $ano)->first();

        if ($b = $existente()) {
            return $this->formatar($b);
        }

        // Evita duas gerações simultâneas (duas pessoas abrindo a home juntas).
        $lock = Cache::lock("max_briefing_{$hoje}_{$ano}", 60);
        if (!$lock->get()) {
            return ['ativo' => true, 'gerando' => true];
        }
        try {
            if ($b = $existente()) {
                return $this->formatar($b);
            }
            $dados = $this->resumoParaIa($ano);
            $r = $this->claude->gerarTexto($this->promptBriefing(), json_encode($dados, JSON_UNESCAPED_UNICODE), 900);
            $pontos = $this->extrairJson($r['texto']);
            if (!$pontos) {
                throw new \RuntimeException('Resposta do Max em formato inesperado.');
            }
            DB::table('max_briefings')->insertOrIgnore([
                'data' => $hoje, 'ano_letivo' => $ano, 'conteudo' => json_encode($pontos, JSON_UNESCAPED_UNICODE),
                'tokens_entrada' => $r['tokens_entrada'], 'tokens_saida' => $r['tokens_saida'],
                'custo_estimado' => $r['tokens_entrada'] * self::CUSTO_ENTRADA + $r['tokens_saida'] * self::CUSTO_SAIDA,
            ]);
            return $this->formatar($existente());
        } catch (\Throwable $e) {
            return ['ativo' => true, 'erro' => 'Não foi possível gerar o briefing agora.'];
        } finally {
            $lock->release();
        }
    }

    /** Análise curta do recorte de dados exibido num gráfico/gaveta. */
    public function explicar(object $jwtUser, string $titulo, array $contexto, ?string $pergunta): array
    {
        $usuarioId = (int) ($jwtUser->id ?? 0);
        $limite = $this->limiter->verificar($usuarioId, 'Administrador');
        if (!($limite['permitido'] ?? true)) {
            throw new \RuntimeException('Você atingiu o limite mensal de perguntas ao Max.');
        }
        $msg = "Gráfico/painel: $titulo\n"
            . ($pergunta ? "Pergunta do diretor: $pergunta\n" : '')
            . 'Dados exibidos (JSON): ' . mb_substr(json_encode($contexto, JSON_UNESCAPED_UNICODE), 0, 12000);
        $r = $this->claude->gerarTexto($this->promptExplicar(), $msg, 600);
        $this->limiter->registrar($usuarioId, $r['tokens_entrada'], $r['tokens_saida']);
        return ['texto' => $r['texto']];
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    private function formatar(object $b): array
    {
        return ['ativo' => true, 'data' => $b->data, 'gerado_em' => $b->gerado_em, 'pontos' => json_decode($b->conteudo, true)];
    }

    /** Recorte compacto dos dados do painel (sem nomes de alunos — só agregados). */
    private function resumoParaIa(int $ano): array
    {
        $p = $this->painel->gerar($ano);
        $freq = $this->painel->detalhe('frequencia', $ano);
        $aprov = $this->painel->detalhe('aproveitamento', $ano);
        $inad = $this->painel->detalhe('inadimplencia', $ano);
        $h = $p['hoje'];

        return [
            'data' => $p['data'], 'ano_letivo' => $ano, 'periodo' => $p['periodo'],
            'hoje' => [
                'dia_letivo' => $h['dia_letivo'],
                'chamadas' => ['lancadas' => $h['chamadas']['lancadas'], 'turmas' => $h['chamadas']['turmas']],
                'presenca_pct' => $h['presenca']['percentual'], 'ausentes' => $h['presenca']['ausentes'],
                'ausentes_com_3_faltas_seguidas' => count(array_filter($h['presenca']['lista_ausentes'], fn ($a) => $a['faltas_seguidas'] >= 3)),
                'avaliacoes_7_dias' => count($h['avaliacoes']['semana']),
            ],
            'indicadores' => $p['kpis'],
            'alertas' => array_map(fn ($a) => $a['titulo'] . ' — ' . $a['detalhe'], $p['alertas']),
            'frequencia_mes_por_turma' => array_map(fn ($t) => [$t['turma'], $t['percentual']], $freq['por_turma']),
            'alunos_abaixo_meta_frequencia' => $freq['total_abaixo_meta'],
            'media_por_turma' => collect($aprov['por_turma'])->map(fn ($t) => [$t->turma, (float) $t->media])->all(),
            'media_por_disciplina' => collect($aprov['por_disciplina'])->map(fn ($d) => [$d->disciplina, (float) $d->media])->all(),
            'alunos_media_abaixo_6' => count($aprov['alunos_abaixo_6']),
            'inadimplencia_por_turma' => $inad['por_turma'],
            'inadimplencia_por_faixa' => $inad['faixas'],
        ];
    }

    private function promptBriefing(): string
    {
        return <<<TXT
Você é o Max, assistente da direção de uma escola brasileira. Receberá os dados do painel de hoje em JSON.
Escreva o "briefing do dia" para o diretor: de 3 a 5 pontos curtos, objetivos, em português do Brasil,
priorizando o que exige ação (queda de frequência, turmas/disciplinas com média baixa, inadimplência,
pendências do dia) e incluindo pelo menos um ponto positivo quando houver. Use números do JSON; não invente dados.
Não cite nomes de alunos. Se o dia não for letivo, foque nos indicadores do período.
Responda APENAS com um array JSON, sem texto fora dele, no formato:
[{"tipo":"alerta|atencao|positivo|info","titulo":"até 60 caracteres","texto":"até 220 caracteres","pergunta":"pergunta de acompanhamento para o Max"}]
TXT;
    }

    private function promptExplicar(): string
    {
        return <<<TXT
Você é o Max, assistente da direção de uma escola brasileira. Explique em português do Brasil o que os dados
do gráfico mostram: a leitura principal, o que chama atenção (destaques, quedas, extremos) e 1 a 2 sugestões
práticas. Seja direto: no máximo 6 frases ou tópicos curtos, em Markdown simples (negrito e listas; sem títulos com #). Use apenas os números enviados.
TXT;
    }

    private function extrairJson(string $texto): ?array
    {
        $ini = strpos($texto, '[');
        $fim = strrpos($texto, ']');
        if ($ini === false || $fim === false) {
            return null;
        }
        $arr = json_decode(substr($texto, $ini, $fim - $ini + 1), true);
        if (!is_array($arr)) {
            return null;
        }
        return array_values(array_filter(array_map(fn ($p) => is_array($p) && !empty($p['titulo']) ? [
            'tipo' => in_array($p['tipo'] ?? '', ['alerta', 'atencao', 'positivo', 'info'], true) ? $p['tipo'] : 'info',
            'titulo' => mb_substr((string) $p['titulo'], 0, 90),
            'texto' => mb_substr((string) ($p['texto'] ?? ''), 0, 300),
            'pergunta' => mb_substr((string) ($p['pergunta'] ?? ''), 0, 200),
        ] : null, $arr)));
    }
}
