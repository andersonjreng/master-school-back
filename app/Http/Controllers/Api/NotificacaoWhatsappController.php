<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use App\Services\Whatsapp\WhatsappNotificacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tela "Notificações WhatsApp" (Configurações): painel da integração, teste
 * manual e histórico dos disparos. O envio diário roda no comando
 * whatsapp:notificacoes-diarias (cron), que usa o mesmo serviço.
 *
 * Tipos de aviso e onde cada um é registrado (dedup):
 * - nota               → notificacoes_whatsapp_enviadas
 * - avaliacao_agendada → notificacoes_whatsapp_avaliacoes_enviadas
 * - mural / recado / ocorrencia → comunicacao_whatsapp_enviados
 */
class NotificacaoWhatsappController extends Controller
{
    public function __construct(
        private WhatsappNotificacaoService $whatsapp,
        private LogSistemaService $log,
    ) {
    }

    /** Estado da integração, cobertura dos responsáveis e números dos últimos 30 dias. */
    public function painel(): JsonResponse
    {
        $resp = DB::table('responsaveis')->selectRaw("
            COUNT(*) AS total,
            COALESCE(SUM(telefone IS NULL OR telefone = ''), 0) AS sem_telefone,
            COALESCE(SUM(notificacao_whatsapp_ativa = 1 AND telefone IS NOT NULL AND telefone <> ''), 0) AS ativos
        ")->first();

        $desde = now()->subDays(30)->startOfDay();
        $porTipo = collect($this->linhasHistorico($desde->toDateString(), now()->toDateString()))
            ->groupBy('tipo')
            ->map(fn ($linhas) => [
                'enviados' => $linhas->where('status', 'enviado')->count(),
                'falhas' => $linhas->where('status', 'falha')->count(),
                'ultimo' => $linhas->max('criado_em'),
            ]);

        $template = fn (string $chave) => (string) config("whatsapp.$chave");
        $tipos = [
            ['id' => 'nota', 'nome' => 'Nota lançada', 'template' => $template('template_name'),
             'descricao' => 'Avisa o responsável quando sai nota nova ou alterada (várias notas do dia viram uma mensagem só).'],
            ['id' => 'avaliacao_agendada', 'nome' => 'Avaliação agendada', 'template' => $template('template_name_avaliacao'),
             'descricao' => 'Avisa os responsáveis da turma quando o professor cadastra uma avaliação, com a data.'],
            ['id' => 'comunicacao', 'nome' => 'Comunicados, recados e ocorrências', 'template' => $template('template_name_comunicacao'),
             'descricao' => 'Avisa sobre comunicado do mural, recado da escola ou ocorrência ainda não lidos no portal.',
             'subtipos' => ['mural', 'recado', 'ocorrencia']],
        ];
        foreach ($tipos as &$t) {
            $ids = $t['subtipos'] ?? [$t['id']];
            $t['ativo'] = $t['template'] !== '';
            $t['enviados_30d'] = collect($ids)->sum(fn ($id) => $porTipo[$id]['enviados'] ?? 0);
            $t['falhas_30d'] = collect($ids)->sum(fn ($id) => $porTipo[$id]['falhas'] ?? 0);
            $t['ultimo_envio'] = collect($ids)->map(fn ($id) => $porTipo[$id]['ultimo'] ?? null)->filter()->max();
        }
        unset($t);

        $phoneId = (string) config('whatsapp.phone_number_id');

        return response()->json([
            'integracao' => [
                'token' => (bool) config('whatsapp.token'),
                'phone_number_id' => $phoneId ? '••••' . substr($phoneId, -4) : null,
                'api_version' => config('whatsapp.api_version'),
                'idioma' => config('whatsapp.template_lang'),
                'portal_url' => config('whatsapp.portal_url'),
            ],
            'responsaveis' => [
                'total' => (int) $resp->total,
                'ativos' => (int) $resp->ativos,
                'sem_telefone' => (int) $resp->sem_telefone,
                'aptos_inativos' => max(0, (int) $resp->total - (int) $resp->sem_telefone - (int) $resp->ativos),
            ],
            'tipos' => $tipos,
            'ultimos_30_dias' => [
                'enviados' => $porTipo->sum('enviados'),
                'falhas' => $porTipo->sum('falhas'),
            ],
        ]);
    }

    /**
     * Dispara agora a mesma checagem do cron diário. Idempotente (mesmas
     * tabelas de dedup) — rodar de novo sobre o mesmo item não reenvia.
     */
    public function testar(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');

        $notas = $this->whatsapp->processarNotificacoesNotas();
        $avaliacoes = $this->whatsapp->processarAvisosAvaliacaoAgendada();
        $comunicacao = $this->whatsapp->processarAvisosComunicacao();

        $detalhes = array_merge(
            array_map(fn ($d) => $d + ['tipo' => 'nota'], $notas['detalhes']),
            array_map(fn ($d) => $d + ['tipo' => 'avaliacao_agendada'], $avaliacoes['detalhes']),
        );

        $enviados = $notas['enviados'] + $avaliacoes['enviados'] + $comunicacao['enviados'];
        $ignorados = $notas['ignorados'] + $avaliacoes['ignorados'] + $comunicacao['ignorados'];
        $falhas = $notas['falhas'] + $avaliacoes['falhas'] + $comunicacao['falhas'];

        $this->log->registrar(
            $request, $jwtUser->id ?? null, $jwtUser->nome ?? 'desconhecido', 'TESTE_NOTIFICACAO_WHATSAPP',
            '/api/whatsapp/testar', 'POST',
            "Disparo manual: $enviados enviado(s), $ignorados já notificado(s), $falhas falha(s).",
            200,
            ['notas' => $notas, 'avaliacoes' => $avaliacoes, 'comunicacao' => $comunicacao]
        );

        return response()->json([
            'success' => true,
            'enviados' => $enviados,
            'ignorados' => $ignorados,
            'falhas' => $falhas,
            'comunicacao' => $comunicacao,
            'detalhes' => $detalhes,
        ]);
    }

    /** Histórico de disparos (todos os tipos). Padrão: últimos 30 dias. */
    public function historico(Request $request): JsonResponse
    {
        $dataInicio = $request->query('data_inicio', now()->subDays(30)->toDateString());
        $dataFim = $request->query('data_fim', now()->toDateString());

        if (!strtotime($dataInicio) || !strtotime($dataFim)) {
            return response()->json(['error' => 'Parâmetros de data inválidos. Use o formato AAAA-MM-DD.'], 400);
        }

        $historico = $this->linhasHistorico($dataInicio, $dataFim);

        return response()->json(['success' => true, 'count' => count($historico), 'data' => $historico]);
    }

    private function linhasHistorico(string $dataInicio, string $dataFim): array
    {
        $rows = DB::select("
            SELECT
                nwe.id, 'nota' AS tipo, nwe.status, nwe.wamid, nwe.erro, nwe.telefone, nwe.criado_em,
                ur.nome_completo AS nome_responsavel, ua.nome_completo AS nome_aluno,
                CONCAT_WS(' - ', d.nome_disciplina, NULLIF(a.descricao, '')) AS assunto
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
                nwa.id, 'avaliacao_agendada' AS tipo, nwa.status, nwa.wamid, nwa.erro, nwa.telefone, nwa.criado_em,
                ur2.nome_completo, ua2.nome_completo,
                CONCAT_WS(' - ', d2.nome_disciplina, NULLIF(a2.descricao, ''))
            FROM notificacoes_whatsapp_avaliacoes_enviadas nwa
            JOIN responsaveis r2 ON nwa.responsavel_id = r2.id
            JOIN usuarios ur2    ON r2.usuario_id = ur2.id
            JOIN alunos al2      ON nwa.aluno_id = al2.id
            JOIN usuarios ua2    ON al2.usuario_id = ua2.id
            JOIN avaliacoes a2   ON nwa.avaliacao_id = a2.id
            JOIN disciplinas d2  ON a2.disciplina_id = d2.id
            WHERE DATE(nwa.criado_em) BETWEEN ? AND ?

            UNION ALL

            SELECT
                c.id, c.tipo, c.status, NULL, c.erro, r3.telefone, c.enviado_em,
                ur3.nome_completo, NULL,
                CASE c.tipo
                    WHEN 'mural' THEN (SELECT titulo FROM mural_posts WHERE id = c.referencia_id)
                    WHEN 'ocorrencia' THEN (SELECT titulo FROM ocorrencias WHERE id = c.referencia_id)
                    ELSE 'Recado da escola'
                END
            FROM comunicacao_whatsapp_enviados c
            JOIN responsaveis r3 ON c.responsavel_id = r3.id
            JOIN usuarios ur3    ON r3.usuario_id = ur3.id
            WHERE DATE(c.enviado_em) BETWEEN ? AND ?

            ORDER BY criado_em DESC
        ", [$dataInicio, $dataFim, $dataInicio, $dataFim, $dataInicio, $dataFim]);

        return array_map(fn ($row) => (array) $row, $rows);
    }
}
