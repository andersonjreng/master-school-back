<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInteressePaiRequest;
use App\Mail\NovoInteressePai;
use App\Models\InteressePai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Landing dos pais (pais.portalmasterschool.com.br): famílias pedem o
 * MasterSchool na escola dos filhos. Rotas públicas, sem login — os dados
 * vão para o banco comercial (ver InteressePai), nunca para o de uma escola.
 */
class InteressePaisController extends Controller
{
    private const CACHE_CONTAGEM = 'interesse_pais:contagem';

    /** POST /api/interesse-pais — recebe o formulário da landing. */
    public function store(StoreInteressePaiRequest $request): JsonResponse
    {
        $d = $request->validated();

        $registro = InteressePai::firstOrNew(['whatsapp' => $d['whatsapp'], 'escola_slug' => $d['escola_slug']]);

        // No reenvio, o nome da escola continua com a grafia do primeiro envio.
        if (!$registro->exists) {
            $registro->escola_nome = $d['escola_nome'];
        }

        $registro->fill([
            'nome'              => $d['nome'],
            'cidade'            => $d['cidade'],
            'etapas'            => $d['etapas'] ?? [],
            'comunicacao_atual' => $d['comunicacao_atual'],
            'interesses'        => $d['interesses'] ?? [],
            'comentario'        => $d['comentario'] ?? null,
            'autorizacao'       => $d['autorizacao'],
            'lider'             => $d['lider'] ?? false,
            'origem'            => $d['origem'] ?? null,
            'indicado_por'      => $d['indicado_por'] ?? null,
            'consentimento_em'  => now(),
            'ip_hash'           => hash('sha256', $request->ip() . config('app.key')),
            'user_agent'        => substr((string) $request->userAgent(), 0, 255),
        ])->save();

        Cache::forget(self::CACHE_CONTAGEM);

        // Avisa por e-mail. Se o SMTP falhar, o pedido continua salvo.
        if ($para = config('services.interesse_pais.notificar')) {
            try {
                Mail::to($para)->send(new NovoInteressePai($registro, $registro->wasRecentlyCreated));
            } catch (\Throwable $e) {
                Log::warning('Falha ao enviar e-mail de interesse de pais', ['erro' => $e->getMessage()]);
            }
        }

        return response()->json(['ok' => true], $registro->wasRecentlyCreated ? 201 : 200);
    }

    /** GET /api/interesse-pais/contagem?escola=nome — alimenta o contador da landing. */
    public function contagem(Request $request): JsonResponse
    {
        $porEscola = Cache::remember(self::CACHE_CONTAGEM, 300, fn () =>
            InteressePai::apresentaveis()
                ->selectRaw('escola_slug, COUNT(*) as total')
                ->groupBy('escola_slug')
                ->pluck('total', 'escola_slug')
                ->all()
        );

        $escola = (string) $request->query('escola', '');
        $slug = $escola !== '' ? InteressePai::slugDaEscola($escola) : '';

        return response()->json([
            'total'  => array_sum($porEscola),
            'escola' => $slug !== '' ? (int) ($porEscola[$slug] ?? 0) : null,
        ]);
    }

    /**
     * GET /api/interesse-pais/exportar?token=...&escola=nome
     * Baixa um CSV (abre no Excel) para montar o relatório da escola.
     * Protegido pelo INTERESSE_PAIS_TOKEN do .env.
     */
    public function exportar(Request $request): StreamedResponse
    {
        $token = (string) config('services.interesse_pais.token');
        abort_unless($token !== '' && hash_equals($token, (string) $request->query('token')), 403);

        $q = InteressePai::query()->orderBy('escola_slug')->orderBy('created_at');
        if ($escola = $request->query('escola')) {
            $q->daEscola((string) $escola);
        }

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel abrir os acentos certo
            fputcsv($out, ['Data', 'Escola', 'Nome', 'WhatsApp', 'Cidade', 'Etapas', 'Comunicação atual',
                           'Interesses', 'Autorização', 'Líder', 'Comentário', 'Origem', 'Indicado por', 'Status'], ';');
            $q->chunk(500, function ($linhas) use ($out) {
                foreach ($linhas as $r) {
                    fputcsv($out, [
                        $r->created_at->format('d/m/Y H:i'),
                        $r->escola_nome,
                        $r->nome,
                        $r->whatsapp,
                        $r->cidade,
                        implode(', ', $r->etapas ?? []),
                        $r->comunicacao_atual,
                        implode(', ', $r->interesses ?? []),
                        ['com_nome' => 'Com nome', 'anonimo' => 'Só contagem', 'nao' => 'Não levar'][$r->autorizacao] ?? $r->autorizacao,
                        $r->lider ? 'Sim' : 'Não',
                        $r->comentario,
                        $r->origem,
                        $r->indicado_por,
                        $r->status,
                    ], ';');
                }
            });
            fclose($out);
        }, 'interesse-pais-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
