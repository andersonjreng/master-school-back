<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Eventos que só o front enxerga: telas visitadas, relatórios/planilhas
 * gerados no navegador, impressões e logout. Qualquer usuário logado envia —
 * o usuário vem sempre do JWT, nunca do corpo da requisição.
 *
 * O front manda em lotes (até 50 por chamada), com a hora em que cada evento
 * aconteceu; horas fora da janela das últimas 24 h viram "agora".
 */
class AuditoriaController extends Controller
{
    /** tipo enviado pelo front → [ação gravada, categoria]. */
    private const TIPOS = [
        'NAVEGACAO'  => ['NAVEGACAO_TELA', 'navegacao'],
        'EXPORTACAO' => ['EXPORTACAO_RELATORIO', 'exportacao'],
        'IMPRESSAO'  => ['IMPRESSAO_DOCUMENTO', 'exportacao'],
        'LOGOUT'     => ['LOGOUT', 'acesso'],
    ];

    public function __construct(private LogSistemaService $log) {}

    public function eventos(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'eventos'               => ['required', 'array', 'min:1', 'max:50'],
            'eventos.*.tipo'        => ['required', 'string', 'in:' . implode(',', array_keys(self::TIPOS))],
            'eventos.*.tela'        => ['nullable', 'string', 'max:255'],
            'eventos.*.titulo'      => ['nullable', 'string', 'max:150'],
            'eventos.*.descricao'   => ['nullable', 'string', 'max:500'],
            'eventos.*.formato'     => ['nullable', 'string', 'max:20'],
            'eventos.*.dados'       => ['nullable', 'array'],
            'eventos.*.ocorrido_em' => ['nullable', 'date'],
        ]);

        $user = $request->attributes->get('max_user');
        $agora = now();

        foreach ($dados['eventos'] as $ev) {
            [$acao, $categoria] = self::TIPOS[$ev['tipo']];
            $tela = $ev['tela'] ?? null;
            $titulo = $ev['titulo'] ?? null;

            $quando = null;
            if (!empty($ev['ocorrido_em'])) {
                $t = Carbon::parse($ev['ocorrido_em'])->setTimezone($agora->getTimezone());
                if ($t->lte($agora) && $t->gte($agora->copy()->subDay())) {
                    $quando = $t->format('Y-m-d H:i:s');
                }
            }

            $descricao = $ev['descricao'] ?? match ($ev['tipo']) {
                'NAVEGACAO'  => 'Acessou a tela ' . ($titulo ?: $tela),
                'EXPORTACAO' => 'Exportou ' . ($titulo ?: 'relatório') . (!empty($ev['formato']) ? ' (' . strtoupper($ev['formato']) . ')' : ''),
                'IMPRESSAO'  => 'Imprimiu ' . ($titulo ?: 'documento'),
                'LOGOUT'     => 'Saiu do sistema',
            };

            $extras = array_filter([
                'titulo'  => $titulo,
                'formato' => $ev['formato'] ?? null,
                'dados'   => isset($ev['dados']) ? array_slice($ev['dados'], 0, 30, true) : null,
            ], fn ($v) => $v !== null);

            $this->log->registrar(
                $request,
                isset($user->id) ? (int) $user->id : null,
                $user->nome ?? null,
                $acao,
                $tela ?? '/',
                'UI',
                mb_substr($descricao, 0, 500),
                200,
                $extras ?: null,
                $categoria,
                $tela,
                $quando,
            );
        }

        return response()->json(['ok' => true, 'registrados' => count($dados['eventos'])]);
    }
}
