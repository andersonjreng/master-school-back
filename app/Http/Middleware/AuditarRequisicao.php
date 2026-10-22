<?php

namespace App\Http\Middleware;

use App\Services\Sistema\AuditoriaCatalogo;
use App\Services\Sistema\LogSistemaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rede de segurança da auditoria: roda em toda rota da API e, depois da
 * resposta pronta, grava em logs_sistema o que o controller não registrou:
 *
 * - escrita (POST/PUT/PATCH/DELETE) de usuário autenticado → ACAO_INCLUSAO/ALTERACAO/EXCLUSAO
 * - qualquer 403 → ACESSO_NEGADO
 * - download de arquivo (PDF, planilha, CSV, ZIP...) → EXPORTACAO_ARQUIVO
 * - erro 5xx → ERRO_SERVIDOR
 *
 * Leituras comuns (GET com JSON) não são gravadas — a navegação entre telas
 * vem do front (POST /api/auditoria/eventos), bem mais legível que uma linha
 * por chamada de API.
 */
class AuditarRequisicao
{
    /** Campos que nunca vão para o log, nem mascarados pela metade. */
    private const SENSIVEIS = '/senha|password|token|secret|api_?key|chave|authorization|cartao|cvv/i';

    /** Rotas que se auditam sozinhas ou que não fazem sentido no log. */
    private const IGNORADAS = ['api/auth/login', 'api/auditoria/*', 'api/webhooks/*'];

    public function __construct(private LogSistemaService $log) {}

    public function handle(Request $request, Closure $next): Response
    {
        $inicio = microtime(true);
        $response = $next($request);

        try {
            $this->auditar($request, $response, (int) round((microtime(true) - $inicio) * 1000));
        } catch (\Throwable) {
            // Auditoria nunca derruba a resposta.
        }

        return $response;
    }

    private function auditar(Request $request, Response $response, int $duracaoMs): void
    {
        if ($request->attributes->get(LogSistemaService::ATRIBUTO_REGISTRADO) || $request->is(...self::IGNORADAS)) {
            return;
        }

        $user = $request->attributes->get('max_user');
        $status = $response->getStatusCode();
        $metodo = $request->method();
        $escrita = isset(AuditoriaCatalogo::ACAO_POR_METODO[$metodo]);

        [$categoria, $modulo] = AuditoriaCatalogo::moduloDaRota($request->path());
        $metodoController = $this->metodoController($request);
        $operacao = AuditoriaCatalogo::operacao($metodoController, $metodo);

        if ($status === 403) {
            $acao = 'ACESSO_NEGADO';
            $categoria = 'acesso';
            $descricao = "Acesso negado em $modulo ($operacao)";
        } elseif ($status >= 500) {
            $acao = 'ERRO_SERVIDOR';
            $categoria = 'sistema';
            $descricao = "Erro interno em $modulo ($operacao)";
        } elseif (!$user) {
            return; // Sem login (401, rota pública): nada a atribuir a alguém.
        } elseif ($this->ehArquivo($response) && $status < 400) {
            $acao = 'EXPORTACAO_ARQUIVO';
            $categoria = 'exportacao';
            $arquivo = $this->nomeArquivo($response);
            $descricao = "$modulo: $operacao" . ($arquivo ? " ($arquivo)" : '');
        } elseif ($escrita) {
            $acao = AuditoriaCatalogo::ACAO_POR_METODO[$metodo];
            $descricao = "$modulo: $operacao" . ($status >= 400 ? ' — recusada' : '');
        } else {
            return;
        }

        $extras = array_filter([
            'rota'        => $request->route()?->uri(),
            'controller'  => $this->nomeController($request),
            'parametros'  => $request->route()?->parameters() ?: null,
            'consulta'    => $this->sanitizar($request->query()) ?: null,
            'dados'       => $escrita ? ($this->sanitizar($request->except(array_keys($request->allFiles()))) ?: null) : null,
            'arquivos'    => $request->allFiles() ? array_keys($request->allFiles()) : null,
            'erro'        => $status >= 400 ? $this->mensagemErro($response) : null,
            'duracao_ms'  => $duracaoMs,
        ], fn ($v) => $v !== null);

        $this->log->registrar(
            $request,
            isset($user->id) ? (int) $user->id : null,
            $user->nome ?? null,
            $acao,
            '/' . $request->path(),
            $metodo,
            $descricao,
            $status,
            $extras,
            $categoria,
        );
    }

    private function metodoController(Request $request): ?string
    {
        $acao = $request->route()?->getActionMethod();

        return $acao && $acao !== 'Closure' ? $acao : null;
    }

    private function nomeController(Request $request): ?string
    {
        $nome = $request->route()?->getActionName();
        if (!$nome || $nome === 'Closure') {
            return null;
        }

        return class_basename(str_replace('@', '::', $nome));
    }

    private function ehArquivo(Response $response): bool
    {
        $tipo = strtolower((string) $response->headers->get('Content-Type'));
        $disposicao = strtolower((string) $response->headers->get('Content-Disposition'));

        return str_contains($disposicao, 'attachment')
            || preg_match('#application/(pdf|zip|octet-stream|vnd\.(ms-excel|openxmlformats))|text/csv#', $tipo) === 1;
    }

    private function nomeArquivo(Response $response): ?string
    {
        $disposicao = (string) $response->headers->get('Content-Disposition');

        return preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposicao, $m) ? rawurldecode($m[1]) : null;
    }

    private function mensagemErro(Response $response): ?string
    {
        $tipo = (string) $response->headers->get('Content-Type');
        if (!str_contains($tipo, 'json')) {
            return null;
        }
        $corpo = json_decode((string) $response->getContent(), true);
        $msg = $corpo['error'] ?? $corpo['message'] ?? null;

        return is_string($msg) ? mb_substr($msg, 0, 300) : null;
    }

    /**
     * Payload enxuto e seguro para o log: sem campos sensíveis, textos longos
     * cortados, listas grandes resumidas e tudo limitado a ~6 KB.
     */
    private function sanitizar(array $dados, int $nivel = 0): array
    {
        $saida = [];
        foreach ($dados as $chave => $valor) {
            if (is_string($chave) && preg_match(self::SENSIVEIS, $chave)) {
                $saida[$chave] = '***';
            } elseif (is_array($valor)) {
                if ($nivel >= 3) {
                    $saida[$chave] = '[' . count($valor) . ' item(ns)]';
                } elseif (array_is_list($valor) && count($valor) > 20) {
                    $saida[$chave] = array_merge(
                        $this->sanitizar(array_slice($valor, 0, 20), $nivel + 1),
                        ['… +' . (count($valor) - 20) . ' item(ns)']
                    );
                } else {
                    $saida[$chave] = $this->sanitizar($valor, $nivel + 1);
                }
            } elseif (is_string($valor) && mb_strlen($valor) > 300) {
                $saida[$chave] = mb_substr($valor, 0, 300) . '…';
            } else {
                $saida[$chave] = $valor;
            }
        }

        if ($nivel === 0 && strlen(json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) > 6000) {
            return ['resumo' => 'Payload grande demais para o log', 'campos' => array_keys($saida)];
        }

        return $saida;
    }
}
