<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\Asaas\AsaasConfigService;
use App\Services\Financeiro\Asaas\AsaasRetornoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/webhooks/asaas — o Asaas avisa aqui cada mudança nas cobranças.
 *
 * - Rota pública (sem JWT): a autenticação é o token que a escola cadastrou
 *   no painel do Asaas, enviado no header `asaas-access-token`.
 * - A escola (banco) é escolhida pelo subdomínio, como em qualquer rota
 *   (ResolveTenantDatabase) — cada escola cadastra a URL do próprio subdomínio.
 * - Todo evento é gravado ANTES de processar, com o id único do Asaas: o mesmo
 *   aviso pode chegar mais de uma vez e só é processado uma.
 * - Respondemos 200 mesmo se o processamento falhar: o evento fica gravado
 *   com erro e a sincronização corrige depois. Responder erro faria o Asaas
 *   reenviar e, depois de 15 falhas seguidas, pausar a fila da escola.
 */
class AsaasWebhookController extends Controller
{
    public function __construct(
        private AsaasConfigService $config,
        private AsaasRetornoService $retorno,
    ) {
    }

    public function receber(Request $request): JsonResponse
    {
        $esperado = $this->config->webhookToken();
        $recebido = (string) $request->header('asaas-access-token', '');
        if (!$esperado || !hash_equals($esperado, $recebido)) {
            Log::warning('Asaas webhook: token inválido', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Não autorizado.'], 401);
        }

        $eventoId = (string) $request->input('id', '');
        $evento = (string) $request->input('event', '');
        $payment = $request->input('payment');
        if ($eventoId === '' || $evento === '') {
            return response()->json(['ok' => true, 'mensagem' => 'Evento sem id — ignorado.']);
        }

        $novo = DB::table('asaas_webhook_eventos')->insertOrIgnore([
            'evento_id'  => $eventoId,
            'evento'     => $evento,
            'payment_id' => is_array($payment) ? ($payment['id'] ?? null) : null,
            'payload'    => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
        ]);
        if (!$novo) {
            return response()->json(['ok' => true, 'mensagem' => 'Evento já recebido.']);
        }

        if (!is_array($payment)) {
            $this->finalizar($eventoId, 'ignorado', 'Evento não é de cobrança.');
            return response()->json(['ok' => true]);
        }

        try {
            $r = $this->retorno->processar($payment, 'webhook', $evento);
            $this->finalizar($eventoId, $r['resultado'], $r['mensagem']);
        } catch (\Throwable $e) {
            Log::error('Asaas webhook: erro ao processar', ['evento' => $eventoId, 'erro' => $e->getMessage()]);
            $this->finalizar($eventoId, 'erro', mb_substr($e->getMessage(), 0, 250));
        }

        return response()->json(['ok' => true]);
    }

    private function finalizar(string $eventoId, string $resultado, string $mensagem): void
    {
        DB::table('asaas_webhook_eventos')->where('evento_id', $eventoId)->update([
            'resultado'     => $resultado,
            'mensagem'      => mb_substr($mensagem, 0, 255),
            'processado_em' => now(),
        ]);
    }
}
