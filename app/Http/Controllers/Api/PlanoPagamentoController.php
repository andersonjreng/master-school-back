<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/financeiro/{get,post,update,delete}_plano.php.
 */
class PlanoPagamentoController extends Controller
{
    public function __construct(private LogSistemaService $log)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $apenasAtivos = $request->query('apenas_ativos', '1') !== '0';

        $query = DB::table('planos_pagamento')
            ->select('id', 'nome', 'descricao', 'valor_total', 'numero_parcelas', 'dia_vencimento', 'ativo', 'criado_em')
            ->orderBy('nome');
        if ($apenasAtivos) {
            $query->where('ativo', 1);
        }

        $planos = $query->get()->map(function ($row) {
            $arr = (array) $row;
            $arr['valor_total'] = (float) $row->valor_total;
            $arr['numero_parcelas'] = (int) $row->numero_parcelas;
            $arr['dia_vencimento'] = (int) $row->dia_vencimento;
            $arr['ativo'] = (bool) $row->ativo;

            return $arr;
        })->values();

        return response()->json(['success' => true, 'count' => $planos->count(), 'data' => $planos]);
    }

    public function store(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');

        $nome = trim((string) $request->input('nome', ''));
        $descricao = trim((string) $request->input('descricao', ''));
        $valorTotal = $request->input('valor_total');
        $numeroParcelas = (int) $request->input('numero_parcelas', 0);
        $diaVencimento = (int) $request->input('dia_vencimento', 0);

        if (!$nome || !$valorTotal || $numeroParcelas < 1 || $diaVencimento < 1 || $diaVencimento > 28) {
            return response()->json([
                'error' => 'Campos inválidos.',
                'message' => 'Nome, valor total, número de parcelas e dia de vencimento (1-28) são obrigatórios.',
            ], 400);
        }

        $id = DB::table('planos_pagamento')->insertGetId([
            'nome' => $nome,
            'descricao' => $descricao,
            'valor_total' => $valorTotal,
            'numero_parcelas' => $numeroParcelas,
            'dia_vencimento' => $diaVencimento,
        ]);

        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'CRIACAO_PLANO_PAGAMENTO',
            '/api/financeiro/planos', 'POST',
            "Plano \"$nome\" criado (R$ $valorTotal / $numeroParcelas parcelas)", 201,
            ['plano_id' => $id]
        );

        return response()->json(['success' => true, 'message' => 'Plano criado com sucesso.', 'id' => $id], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');

        $id = (int) $request->input('id', 0);
        $nome = trim((string) $request->input('nome', ''));
        $descricao = trim((string) $request->input('descricao', ''));
        $valorTotal = $request->input('valor_total');
        $numeroParcelas = (int) $request->input('numero_parcelas', 0);
        $diaVencimento = (int) $request->input('dia_vencimento', 0);
        $ativoInput = $request->input('ativo');

        if (!$id || !$nome || !$valorTotal || $numeroParcelas < 1 || $diaVencimento < 1 || $diaVencimento > 28) {
            return response()->json(['error' => 'Campos inválidos.'], 400);
        }

        $atual = DB::table('planos_pagamento')->where('id', $id)->value('ativo');
        if ($atual === null) {
            return response()->json(['error' => 'Plano não encontrado.'], 404);
        }
        $ativo = $ativoInput === null ? (int) $atual : (int) (bool) $ativoInput;

        // Não trata 0 linhas afetadas como erro: sem CLIENT_FOUND_ROWS, o driver
        // reporta "linhas alteradas" (não "linhas encontradas") — reenviar os
        // mesmos valores retornaria 0 mesmo com o UPDATE tendo funcionado.
        DB::table('planos_pagamento')->where('id', $id)->update([
            'nome' => $nome,
            'descricao' => $descricao,
            'valor_total' => $valorTotal,
            'numero_parcelas' => $numeroParcelas,
            'dia_vencimento' => $diaVencimento,
            'ativo' => $ativo,
        ]);

        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'ATUALIZACAO_PLANO_PAGAMENTO',
            '/api/financeiro/planos', 'PUT', "Plano #$id \"$nome\" atualizado", 200, ['plano_id' => $id]
        );

        return response()->json(['success' => true, 'message' => 'Plano atualizado com sucesso.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $id = (int) $request->input('id', 0);

        if (!$id) {
            return response()->json(['error' => 'ID do plano é obrigatório.'], 400);
        }

        if (!DB::table('planos_pagamento')->where('id', $id)->exists()) {
            return response()->json(['error' => 'Plano não encontrado.'], 404);
        }

        $emUso = DB::table('matriculas_financeiras')->where('plano_id', $id)->where('status', 'ativa')->exists();
        if ($emUso) {
            return response()->json([
                'error' => 'Plano em uso.',
                'message' => 'Este plano possui matrículas ativas vinculadas. Inative-o ao invés de excluir.',
            ], 409);
        }

        DB::table('planos_pagamento')->where('id', $id)->update(['ativo' => 0]);

        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'INATIVACAO_PLANO_PAGAMENTO',
            '/api/financeiro/planos', 'DELETE', "Plano #$id inativado", 200, ['plano_id' => $id]
        );

        return response()->json(['success' => true, 'message' => 'Plano inativado com sucesso.']);
    }
}
