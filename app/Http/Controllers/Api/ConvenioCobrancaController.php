<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\Sicoob\ConvenioCobrancaService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Equivalente a api/financeiro/convenio/{get,post}_convenio_cobranca.php.
 */
class ConvenioCobrancaController extends Controller
{
    public function __construct(
        private ConvenioCobrancaService $convenios,
        private LogSistemaService $log,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $bancoCodigo = trim((string) $request->query('banco_codigo', '756'));
        $convenio = $this->convenios->carregar($bancoCodigo);

        return response()->json(['success' => true, 'convenio' => $convenio]);
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');

        $dados = [
            'banco_codigo' => trim((string) $request->input('banco_codigo', '756')),
            'banco_nome' => trim((string) $request->input('banco_nome', '')),
            'codigo_cedente' => trim((string) $request->input('codigo_cedente', '')),
            'codigo_cedente_dv' => trim((string) $request->input('codigo_cedente_dv', '')) ?: null,
            'agencia' => trim((string) $request->input('agencia', '')),
            'agencia_dv' => trim((string) $request->input('agencia_dv', '')) ?: null,
            'conta' => trim((string) $request->input('conta', '')),
            'carteira' => trim((string) $request->input('carteira', '')),
            'variacao_carteira' => trim((string) $request->input('variacao_carteira', '')) ?: null,
            'ativo' => (bool) $request->input('ativo', true),
        ];

        if (!$dados['banco_nome'] || !$dados['codigo_cedente'] || !$dados['agencia'] || !$dados['carteira']) {
            return response()->json(['error' => 'Campos obrigatórios: nome do banco, código do cedente, agência e carteira.'], 400);
        }
        // "conta" e os dígitos verificadores ficam opcionais aqui — nem sempre são
        // visíveis no boleto físico. O gerador de remessa avisa (sem bloquear)
        // quando algum deles está faltando.

        $convenio = $this->convenios->salvar($dados);

        $this->log->registrar(
            $request, $jwtUser->id ?? null, $jwtUser->nome ?? null, 'SALVAR_CONVENIO_COBRANCA',
            '/api/financeiro/convenio', 'POST',
            "Convênio de cobrança \"{$dados['banco_nome']}\" (cedente {$dados['codigo_cedente']}) salvo", 200,
            ['convenio_id' => $convenio['id']]
        );

        return response()->json(['success' => true, 'convenio' => $convenio]);
    }
}
