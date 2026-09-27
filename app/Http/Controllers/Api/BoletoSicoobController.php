<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\Sicoob\BoletoVisualService;
use App\Services\Financeiro\Sicoob\RemessaSicoobService;
use App\Services\Financeiro\Sicoob\RetornoSicoobService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Equivalente a api/financeiro/convenio/{get_boleto_html,post_gerar_remessa,
 * post_importar_retorno}.php.
 */
class BoletoSicoobController extends Controller
{
    public function __construct(
        private BoletoVisualService $boletoVisual,
        private RemessaSicoobService $remessa,
        private RetornoSicoobService $retorno,
        private LogSistemaService $log,
    ) {
    }

    public function boletoHtml(Request $request): JsonResponse
    {
        $parcelaId = (int) $request->query('parcela_id', 0);
        if (!$parcelaId) {
            return response()->json(['error' => 'Informe parcela_id.'], 400);
        }

        try {
            $html = $this->boletoVisual->gerarHtml($parcelaId);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // TypeError/Error não são Exception — sem isso, um erro de tipo vindo da
            // biblioteca de boleto vira 500 sem corpo nenhum.
            return response()->json([
                'error' => 'Erro interno ao gerar o boleto.',
                'message' => $e->getMessage(),
                'tipo' => get_class($e),
                'arquivo' => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }

        return response()->json(['success' => true, 'html' => $html]);
    }

    public function gerarRemessa(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);

        $parcelaIds = array_map('intval', (array) $request->input('parcela_ids', []));
        $parcelaIds = array_values(array_unique(array_filter($parcelaIds)));

        if (!$parcelaIds) {
            return response()->json(['error' => 'Informe ao menos uma parcela (parcela_ids).'], 400);
        }

        try {
            $resultado = $this->remessa->gerar($parcelaIds, $usuarioId);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Erro interno ao gerar a remessa.',
                'message' => $e->getMessage(),
                'tipo' => get_class($e),
                'arquivo' => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }

        $this->log->registrar(
            $request, $usuarioId, $jwtUser->nome ?? null, 'GERAR_REMESSA_SICOOB',
            '/api/financeiro/convenio/gerar-remessa', 'POST',
            "Remessa \"{$resultado['nome_arquivo']}\" gerada com {$resultado['quantidade']} boleto(s)", 201,
            ['remessa_id' => $resultado['remessa_id'], 'quantidade' => $resultado['quantidade']]
        );

        // O conteúdo do CNAB é ISO-8859-1 (não UTF-8) — json_encode() quebraria com
        // bytes assim, por isso vai em base64. O frontend decodifica e monta o
        // arquivo pra download sem converter encoding.
        return response()->json([
            'success' => true,
            'nome_arquivo' => $resultado['nome_arquivo'],
            'quantidade' => $resultado['quantidade'],
            'conteudo_base64' => base64_encode($resultado['conteudo']),
            'avisos' => $resultado['avisos'],
            'puladas' => $resultado['puladas'],
        ], 201);
    }

    public function importarRetorno(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);

        $arquivo = $request->file('arquivo');
        if (!$arquivo || !$arquivo->isValid()) {
            return response()->json(['error' => 'Envie o arquivo de retorno (.RET) no campo "arquivo".'], 400);
        }

        // O arquivo real do Sicoob vem em ISO-8859-1 — lido como bytes crus, sem
        // qualquer conversão (a biblioteca espera exatamente esse encoding).
        $conteudo = file_get_contents($arquivo->getRealPath());
        $nomeArquivo = basename($arquivo->getClientOriginalName());

        if ($conteudo === false || $conteudo === '') {
            return response()->json(['error' => 'Arquivo vazio ou não pôde ser lido.'], 400);
        }

        try {
            $resultado = $this->retorno->processar($conteudo, $nomeArquivo, $usuarioId);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Erro interno ao processar o retorno.',
                'message' => $e->getMessage(),
                'tipo' => get_class($e),
                'arquivo' => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }

        $this->log->registrar(
            $request, $usuarioId, $jwtUser->nome ?? null, 'IMPORTAR_RETORNO_SICOOB',
            '/api/financeiro/convenio/importar-retorno', 'POST',
            "Retorno \"$nomeArquivo\" importado — " . count($resultado['pagos']) . ' baixa(s) aplicada(s)', 200,
            ['retorno_id' => $resultado['retorno_id']]
        );

        return response()->json([
            'success' => true,
            'pagos' => $resultado['pagos'],
            'revisar' => $resultado['revisar'],
            'erros' => $resultado['erros'],
        ]);
    }
}
