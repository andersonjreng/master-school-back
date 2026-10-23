<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Rematricula\RematriculaService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Rematrícula online — escola (campanhas, painel) e família (aceite/recusa).
 * Regras em RematriculaService.
 */
class RematriculaController extends Controller
{
    public function __construct(private RematriculaService $svc, private LogSistemaService $log)
    {
    }

    // ── Escola ──────────────────────────────────────────────────────────

    public function campanhas(): JsonResponse
    {
        if (!$this->svc->disponivel()) {
            return response()->json(['data' => [], 'indisponivel' => true]);
        }
        return response()->json(['data' => $this->svc->campanhas()]);
    }

    public function nova(): JsonResponse
    {
        return response()->json(['data' => $this->svc->novaSugerida()]);
    }

    public function campanha(int $id): JsonResponse
    {
        $c = $this->svc->campanha($id);
        return $c ? response()->json(['data' => $c]) : response()->json(['error' => 'Rematrícula não encontrada.'], 404);
    }

    public function turmas(Request $request): JsonResponse
    {
        $ano = (int) $request->query('ano_destino');
        return response()->json(['data' => $this->svc->turmasDoAno($ano) + ['sugestao' => $this->svc->sugerirTurmas($ano)]]);
    }

    public function modelo(): JsonResponse
    {
        return response()->json(['data' => $this->svc->modeloPadrao()]);
    }

    public function previa(Request $request): JsonResponse
    {
        return response()->json(['data' => ['html' => $this->svc->previaModelo($request->all())]]);
    }

    public function salvar(Request $request, ?int $id = null): JsonResponse
    {
        $usuario = $request->attributes->get('max_user');
        try {
            $id = $this->svc->salvar($id, $request->all(), (int) ($usuario->id ?? 0));
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true, 'data' => $this->svc->campanha($id)]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $status = (string) $request->input('status');
        if (!in_array($status, ['aberta', 'encerrada', 'rascunho'], true)) {
            return response()->json(['error' => 'Situação inválida.'], 422);
        }
        try {
            $this->svc->mudarStatus($id, $status);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->registrar($request, 'REMATRICULA_CAMPANHA', "Rematrícula #$id: $status");
        return response()->json(['success' => true]);
    }

    public function excluir(int $id): JsonResponse
    {
        try {
            $this->svc->excluir($id);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true]);
    }

    public function painel(int $id): JsonResponse
    {
        $p = $this->svc->painel($id);
        return $p ? response()->json(['data' => $p]) : response()->json(['error' => 'Rematrícula não encontrada.'], 404);
    }

    public function lembrar(Request $request, int $id): JsonResponse
    {
        $n = $this->svc->lembrar($id);
        return response()->json(['success' => true, 'familias' => $n]);
    }

    public function cancelar(Request $request, int $id): JsonResponse
    {
        $usuario = $request->attributes->get('max_user');
        try {
            $this->svc->cancelar($id, (int) ($usuario->id ?? 0));
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->registrar($request, 'REMATRICULA_CANCELADA', "Rematrícula #$id cancelada pela escola");
        return response()->json(['success' => true]);
    }

    public function contratoEscola(int $id): Response|JsonResponse
    {
        $c = $this->svc->contratoAceito($id);
        return $c ? $this->paginaContrato($c) : response()->json(['error' => 'Contrato não encontrado.'], 404);
    }

    public function situacaoVirada(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->svc->situacaoParaVirada((int) $request->query('turma_origem_id'))]);
    }

    // ── Família ─────────────────────────────────────────────────────────

    public function minha(Request $request): JsonResponse
    {
        $usuario = $request->attributes->get('max_user');
        return response()->json(['data' => $this->svc->paraResponsavel((int) ($usuario->id ?? 0))]);
    }

    public function contratoFamilia(Request $request, int $alunoId): JsonResponse
    {
        $usuario = $request->attributes->get('max_user');
        try {
            $c = $this->svc->contratoParaAceite((int) ($usuario->id ?? 0), $alunoId, (int) $request->query('plano_id'));
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['data' => $c]);
    }

    public function aceitar(Request $request, int $alunoId): JsonResponse
    {
        $usuario = $request->attributes->get('max_user');
        try {
            $r = $this->svc->aceitar(
                (int) ($usuario->id ?? 0), $alunoId, (int) $request->input('plano_id'), (string) $request->input('hash'),
                $request->ip(), $request->userAgent(),
            );
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Não foi possível concluir a rematrícula: ' . $e->getMessage()], 422);
        }
        $this->registrar($request, 'REMATRICULA_ACEITE', "Contrato da rematrícula aceito (aluno #$alunoId)", ['aluno_id' => $alunoId, 'rematricula_id' => $r['id']]);
        return response()->json(['success' => true, 'data' => $r]);
    }

    public function recusar(Request $request, int $alunoId): JsonResponse
    {
        $usuario = $request->attributes->get('max_user');
        try {
            $this->svc->recusar((int) ($usuario->id ?? 0), $alunoId, $request->input('motivo'));
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->registrar($request, 'REMATRICULA_RECUSA', "Família informou que não vai renovar (aluno #$alunoId)", ['aluno_id' => $alunoId]);
        return response()->json(['success' => true]);
    }

    public function desfazerRecusa(Request $request, int $alunoId): JsonResponse
    {
        $usuario = $request->attributes->get('max_user');
        try {
            $this->svc->desfazerRecusa((int) ($usuario->id ?? 0), $alunoId);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true]);
    }

    public function meuContrato(Request $request, int $id): Response|JsonResponse
    {
        $usuario = $request->attributes->get('max_user');
        if (!$this->svc->podeVerContrato((int) ($usuario->id ?? 0), $id)) {
            return response()->json(['error' => 'Contrato não encontrado.'], 404);
        }
        $c = $this->svc->contratoAceito($id);
        return $c ? $this->paginaContrato($c) : response()->json(['error' => 'Contrato não encontrado.'], 404);
    }

    // ── Apoio ───────────────────────────────────────────────────────────

    /** Página do contrato aceito, pronta para imprimir/salvar em PDF, com o registro do aceite. */
    private function paginaContrato(array $c): Response
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $quando = $c['aceite_em'] ? date('d/m/Y \à\s H:i:s', strtotime($c['aceite_em'])) : '—';
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Contrato — ' . $e($c['aluno']) . '</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>body{font-family:Georgia,serif;max-width:780px;margin:24px auto;padding:0 16px;line-height:1.55;color:#111}'
            . 'h3{font-size:1rem;margin:1.4em 0 .4em}h3:first-child{text-align:center;font-size:1.1rem}p{text-align:justify}'
            . '.aceite{margin-top:2em;padding:12px 14px;border:1px solid #999;border-radius:6px;font:13px/1.5 Arial,sans-serif;background:#f6f6f6}'
            . '.aceite code{word-break:break-all}@media print{.aceite{break-inside:avoid}}</style></head><body>'
            . $c['contrato_html']
            . '<div class="aceite"><b>Registro do aceite eletrônico</b><br>'
            . 'Aceito por: ' . $e($c['aceito_por'] ?? '—') . '<br>'
            . 'Data e hora: ' . $e($quando) . '<br>'
            . 'Endereço IP: ' . $e($c['aceite_ip'] ?? '—') . '<br>'
            . 'Aparelho: ' . $e($c['aceite_user_agent'] ?? '—') . '<br>'
            . 'Código de verificação (SHA-256 do texto aceito): <code>' . $e($c['contrato_hash']) . '</code></div>'
            . '</body></html>';
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function registrar(Request $request, string $acao, string $descricao, array $dados = []): void
    {
        $usuario = $request->attributes->get('max_user');
        $this->log->registrar($request, (int) ($usuario->id ?? 0), $usuario->nome ?? 'desconhecido', $acao,
            '/' . $request->path(), $request->method(), $descricao, 200, $dados);
    }
}
