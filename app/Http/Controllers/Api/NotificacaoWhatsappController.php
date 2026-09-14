<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use App\Services\Whatsapp\WhatsappNotificacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/whatsapp/{testar_notificacoes,get_historico_notificacoes}.php.
 *
 * api/whatsapp/enviar_notificacoes_diarias.php (cron CLI multi-tenant, varre
 * várias escolas/bancos) NÃO foi portado nesta etapa — não é um endpoint HTTP
 * chamado pelo frontend, e migrar o cron exigiria decidir como este backend
 * Laravel local (single-tenant) vai lidar com múltiplos bancos por escola.
 * Continua rodando como cron PHP legado por enquanto.
 */
class NotificacaoWhatsappController extends Controller
{
    public function __construct(
        private WhatsappNotificacaoService $whatsapp,
        private LogSistemaService $log,
    ) {
    }

    /**
     * Dispara manualmente, sob demanda, a mesma checagem que o cron diário faz.
     * Idempotente (mesmas tabelas de dedup do cron real) — rodar de novo sobre a
     * mesma nota/avaliação não reenvia.
     */
    public function testar(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLog = $jwtUser->id ?? null;
        $usernameLog = $jwtUser->nome ?? 'desconhecido';

        $resultadoNotas = $this->whatsapp->processarNotificacoesNotas();
        $resultadoAvaliacoes = $this->whatsapp->processarAvisosAvaliacaoAgendada();

        $detalhesNotas = array_map(fn ($d) => $d + ['tipo' => 'nota'], $resultadoNotas['detalhes']);
        $detalhesAvaliacoes = array_map(fn ($d) => $d + ['tipo' => 'avaliacao_agendada'], $resultadoAvaliacoes['detalhes']);

        $enviados = $resultadoNotas['enviados'] + $resultadoAvaliacoes['enviados'];
        $ignorados = $resultadoNotas['ignorados'] + $resultadoAvaliacoes['ignorados'];
        $falhas = $resultadoNotas['falhas'] + $resultadoAvaliacoes['falhas'];

        $this->log->registrar(
            $request, $usuarioIdLog, $usernameLog, 'TESTE_NOTIFICACAO_WHATSAPP',
            '/api/whatsapp/testar', 'POST',
            "Disparo manual de teste: $enviados enviado(s), $ignorados já notificado(s), $falhas falha(s) (notas + avaliações agendadas).",
            200,
            ['notas' => $resultadoNotas, 'avaliacoes' => $resultadoAvaliacoes]
        );

        return response()->json([
            'success' => true,
            'enviados' => $enviados,
            'ignorados' => $ignorados,
            'falhas' => $falhas,
            'detalhes' => array_merge($detalhesNotas, $detalhesAvaliacoes),
        ]);
    }

    /**
     * Histórico de notificações já disparadas — une os dois tipos (nota lançada e
     * avaliação agendada). Por padrão retorna os últimos 30 dias.
     */
    public function historico(Request $request): JsonResponse
    {
        $dataInicio = $request->query('data_inicio', now()->subDays(30)->toDateString());
        $dataFim = $request->query('data_fim', now()->toDateString());

        if (!strtotime($dataInicio) || !strtotime($dataFim)) {
            return response()->json(['error' => 'Parâmetros de data inválidos. Use o formato AAAA-MM-DD.'], 400);
        }

        $rows = DB::select('
            SELECT
                nwe.id, \'nota\' AS tipo, nwe.status, nwe.wamid, nwe.erro, nwe.telefone, nwe.criado_em,
                ur.nome_completo AS nome_responsavel, ua.nome_completo AS nome_aluno,
                d.nome_disciplina, a.descricao AS avaliacao_descricao
            FROM notificacoes_whatsapp_enviadas nwe
            JOIN responsaveis r ON nwe.responsavel_id = r.id
            JOIN usuarios ur    ON r.usuario_id = ur.id
            JOIN alunos al      ON nwe.aluno_id = al.id
            JOIN usuarios ua    ON al.usuario_id = ua.id
            JOIN avaliacoes a   ON nwe.avaliacao_id = a.id
            JOIN disciplinas d  ON a.disciplina_id = d.id
            WHERE DATE(nwe.criado_em) BETWEEN ? AND ?

            UNION ALL

            SELECT
                nwa.id, \'avaliacao_agendada\' AS tipo, nwa.status, nwa.wamid, nwa.erro, nwa.telefone, nwa.criado_em,
                ur2.nome_completo AS nome_responsavel, ua2.nome_completo AS nome_aluno,
                d2.nome_disciplina, a2.descricao AS avaliacao_descricao
            FROM notificacoes_whatsapp_avaliacoes_enviadas nwa
            JOIN responsaveis r2 ON nwa.responsavel_id = r2.id
            JOIN usuarios ur2    ON r2.usuario_id = ur2.id
            JOIN alunos al2      ON nwa.aluno_id = al2.id
            JOIN usuarios ua2    ON al2.usuario_id = ua2.id
            JOIN avaliacoes a2   ON nwa.avaliacao_id = a2.id
            JOIN disciplinas d2  ON a2.disciplina_id = d2.id
            WHERE DATE(nwa.criado_em) BETWEEN ? AND ?

            ORDER BY criado_em DESC
        ', [$dataInicio, $dataFim, $dataInicio, $dataFim]);

        $historico = array_map(function ($row) {
            $arr = (array) $row;
            $arr['avaliacao'] = trim(($row->nome_disciplina ?? '') . ' - ' . ($row->avaliacao_descricao ?? ''), ' -');
            unset($arr['nome_disciplina'], $arr['avaliacao_descricao']);

            return $arr;
        }, $rows);

        return response()->json(['success' => true, 'count' => count($historico), 'data' => $historico]);
    }
}
