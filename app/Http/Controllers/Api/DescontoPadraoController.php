<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/financeiro/{get,post,update}_desconto.php.
 */
class DescontoPadraoController extends Controller
{
    public function __construct(private LogSistemaService $log)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $apenasAtivos = $request->query('apenas_ativos', '1') !== '0';

        $query = DB::table('descontos_padrao')
            ->select('id', 'nome', 'tipo', 'valor', 'descricao', 'ativo', 'criado_em')
            ->orderBy('nome');
        if ($apenasAtivos) {
            $query->where('ativo', 1);
        }

        $descontos = $query->get()->map(function ($row) {
            $arr = (array) $row;
            $arr['valor'] = (float) $row->valor;
            $arr['ativo'] = (bool) $row->ativo;

            return $arr;
        })->values();

        return response()->json(['success' => true, 'count' => $descontos->count(), 'data' => $descontos]);
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');

        $nome = trim((string) $request->input('nome', ''));
        $tipo = $request->input('tipo', '');
        $valor = $request->input('valor');
        $descricao = trim((string) $request->input('descricao', ''));

        if (!$nome || !in_array($tipo, ['percentual', 'valor'], true) || $valor === null || (float) $valor <= 0) {
            return response()->json([
                'error' => 'Campos inválidos.',
                'message' => 'Nome, tipo (percentual|valor) e valor positivo são obrigatórios.',
            ], 400);
        }
        if ($tipo === 'percentual' && (float) $valor > 100) {
            return response()->json(['error' => 'Percentual inválido.', 'message' => 'O percentual não pode ser superior a 100%.'], 400);
        }

        $id = DB::table('descontos_padrao')->insertGetId([
            'nome' => $nome,
            'tipo' => $tipo,
            'valor' => $valor,
            'descricao' => $descricao,
        ]);

        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'CRIACAO_DESCONTO',
            '/api/financeiro/descontos', 'POST', "Desconto \"$nome\" criado", 201, ['desconto_id' => $id]
        );

        return response()->json(['success' => true, 'message' => 'Desconto criado com sucesso.', 'id' => $id], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');

        $id = (int) $request->input('id', 0);
        $nome = trim((string) $request->input('nome', ''));
        $tipo = $request->input('tipo', '');
        $valor = $request->input('valor');
        $descricao = trim((string) $request->input('descricao', ''));
        $ativo = (int) (bool) $request->input('ativo', true);

        if (!$id || !$nome || !in_array($tipo, ['percentual', 'valor'], true) || $valor === null || (float) $valor <= 0) {
            return response()->json(['error' => 'Campos inválidos.'], 400);
        }
        if (!DB::table('descontos_padrao')->where('id', $id)->exists()) {
            return response()->json(['error' => 'Desconto não encontrado.'], 404);
        }

        DB::table('descontos_padrao')->where('id', $id)->update([
            'nome' => $nome,
            'tipo' => $tipo,
            'valor' => $valor,
            'descricao' => $descricao,
            'ativo' => $ativo,
        ]);

        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'ATUALIZACAO_DESCONTO',
            '/api/financeiro/descontos', 'PUT', "Desconto #$id atualizado", 200, ['desconto_id' => $id]
        );

        return response()->json(['success' => true, 'message' => 'Desconto atualizado com sucesso.']);
    }
}
