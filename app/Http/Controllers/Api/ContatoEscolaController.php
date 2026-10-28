<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContatoEscolaRequest;
use App\Mail\NovoContatoEscola;
use App\Models\ContatoEscola;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Site institucional (portalmasterschool.com.br): escolas pedem contato ou
 * demonstração. Rotas públicas, sem login — dados no banco comercial.
 * O site fica em outro host, então chega aqui por CORS (liberado para api/*).
 */
class ContatoEscolaController extends Controller
{
    /** POST /api/contato-escola */
    public function store(StoreContatoEscolaRequest $request): JsonResponse
    {
        $d = $request->validated();

        $contato = ContatoEscola::create([
            'nome'             => $d['nome'],
            'cargo'            => $d['cargo'],
            'whatsapp'         => $d['whatsapp'],
            'email'            => $d['email'] ?? null,
            'escola'           => $d['escola'],
            'cidade'           => $d['cidade'],
            'alunos'           => $d['alunos'] ?? null,
            'interesses'       => $d['interesses'] ?? [],
            'mensagem'         => $d['mensagem'] ?? null,
            'origem'           => $d['origem'] ?? null,
            'consentimento_em' => now(),
            'ip_hash'          => hash('sha256', $request->ip() . config('app.key')),
            'user_agent'       => substr((string) $request->userAgent(), 0, 255),
        ]);

        // Avisa por e-mail. Se o SMTP falhar, o contato continua salvo.
        if ($para = config('services.contato_escolas.notificar')) {
            try {
                Mail::to($para)->send(new NovoContatoEscola($contato));
            } catch (\Throwable $e) {
                Log::warning('Falha ao enviar e-mail de contato de escola', ['erro' => $e->getMessage()]);
            }
        }

        return response()->json(['ok' => true], 201);
    }

    /** GET /api/contato-escola/exportar?token=... — CSV para o Excel. */
    public function exportar(Request $request): StreamedResponse
    {
        $token = (string) config('services.contato_escolas.token');
        abort_unless($token !== '' && hash_equals($token, (string) $request->query('token')), 403);

        $q = ContatoEscola::query()->orderByDesc('created_at');

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel abrir os acentos certo
            fputcsv($out, ['Data', 'Escola', 'Cidade', 'Alunos', 'Nome', 'Cargo', 'WhatsApp', 'E-mail',
                           'Interesses', 'Mensagem', 'Origem', 'Status'], ';');
            $q->chunk(500, function ($linhas) use ($out) {
                foreach ($linhas as $c) {
                    fputcsv($out, [
                        $c->created_at->format('d/m/Y H:i'),
                        $c->escola, $c->cidade, $c->alunos,
                        $c->nome, $c->cargo, $c->whatsapp, $c->email,
                        implode(', ', $c->interesses ?? []),
                        $c->mensagem, $c->origem, $c->status,
                    ], ';');
                }
            });
            fclose($out);
        }, 'contato-escolas-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
