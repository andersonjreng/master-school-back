<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Direcao\MaxDirecaoService;
use App\Services\Direcao\PainelDirecaoService;
use App\Support\Permissoes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Home da gestão (permissão painel.direcao). Os números financeiros só vão
 * para quem também opera o financeiro — Coordenação e Secretaria veem o
 * painel sem eles.
 */
class PainelDirecaoController extends Controller
{
    public function __construct(
        private PainelDirecaoService $painel,
        private MaxDirecaoService $max,
    ) {
    }

    /** Briefing do dia (gerado 1x por dia; pode estar desligado). */
    public function briefing(Request $request): JsonResponse
    {
        $briefing = $this->max->briefing($this->ano($request));
        if (!$this->veFinanceiro($request) && !empty($briefing['pontos'])) {
            $briefing['pontos'] = array_values(array_filter($briefing['pontos'], fn ($p) => ($p['area'] ?? '') !== 'financeiro'));
        }
        return response()->json(['success' => true, 'data' => $briefing]);
    }

    /** "Explicar" de um gráfico: o front manda o título e os dados exibidos. */
    public function explicar(Request $request): JsonResponse
    {
        $titulo = trim((string) $request->input('titulo', ''));
        $contexto = (array) $request->input('contexto', []);
        if (!$titulo || !$contexto) {
            return response()->json(['error' => 'Informe o título e os dados do gráfico.'], 422);
        }
        try {
            $r = $this->max->explicar($request->attributes->get('max_user'), mb_substr($titulo, 0, 120), $contexto,
                $request->filled('pergunta') ? mb_substr((string) $request->input('pergunta'), 0, 300) : null);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 429);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'O Max não conseguiu responder agora. Tente de novo em instantes.'], 502);
        }
        return response()->json(['success' => true, 'data' => $r]);
    }

    /** Parametrizações → Assistente Max. */
    public function configMax(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['briefing_diario' => $this->max->briefingAtivo(), 'uso_mes' => $this->max->usoDoMes()]]);
    }

    public function salvarConfigMax(Request $request): JsonResponse
    {
        $this->max->definirBriefingAtivo($request->boolean('briefing_diario'));
        return $this->configMax();
    }

    public function index(Request $request): JsonResponse
    {
        $dados = $this->painel->gerar($this->ano($request));
        $dados['financeiro_visivel'] = $this->veFinanceiro($request);
        if (!$dados['financeiro_visivel']) {
            unset($dados['hoje']['financeiro'], $dados['kpis']['receita'], $dados['kpis']['inadimplencia']);
            $dados['alertas'] = array_values(array_filter($dados['alertas'],
                fn ($a) => !str_starts_with((string) ($a['link'] ?? ''), '/financeiro')));
        }
        return response()->json(['success' => true, 'data' => $dados]);
    }

    public function detalhe(Request $request, string $tipo): JsonResponse
    {
        if (in_array($tipo, ['receita', 'inadimplencia'], true) && !$this->veFinanceiro($request)) {
            return response()->json(['error' => 'Acesso negado. Seu perfil não acessa os números do financeiro.'], 403);
        }
        try {
            $data = $this->painel->detalhe($tipo, $this->ano($request), $request->only(['mes', 'turma_id']));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
        return response()->json(['success' => true, 'data' => $data]);
    }

    private function veFinanceiro(Request $request): bool
    {
        return Permissoes::pode($this->funcoes($request), Permissoes::FINANCEIRO_OPERAR);
    }

    private function ano(Request $request): int
    {
        $ano = (int) $request->query('ano', 0);
        return $ano >= 2000 && $ano <= 2100 ? $ano : (int) now()->year;
    }
}
