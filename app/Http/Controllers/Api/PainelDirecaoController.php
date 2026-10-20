<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Direcao\MaxDirecaoService;
use App\Services\Direcao\PainelDirecaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Home da direção (Administrador e Diretoria). */
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
        return response()->json(['success' => true, 'data' => $this->max->briefing($this->ano($request))]);
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
        return response()->json(['success' => true, 'data' => $this->painel->gerar($this->ano($request))]);
    }

    public function detalhe(Request $request, string $tipo): JsonResponse
    {
        try {
            $data = $this->painel->detalhe($tipo, $this->ano($request), $request->only(['mes', 'turma_id']));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
        return response()->json(['success' => true, 'data' => $data]);
    }

    private function ano(Request $request): int
    {
        $ano = (int) $request->query('ano', 0);
        return $ano >= 2000 && $ano <= 2100 ? $ano : (int) now()->year;
    }
}
