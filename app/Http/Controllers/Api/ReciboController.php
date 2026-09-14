<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/financeiro/get_recibo.php.
 */
class ReciboController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioIdLogado = (int) ($jwtUser->id ?? 0);
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $isPrivilegiado = !empty(array_intersect(['Administrador', 'Diretoria'], $funcoes));
        $isResponsavel = in_array('Responsável', $funcoes, true);

        if (!$isPrivilegiado && !$isResponsavel) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $reciboId = $request->query('recibo_id');
        $parcelaId = $request->query('parcela_id');
        $numeroRecibo = $request->query('numero_recibo');
        $alunoId = $request->query('aluno_id');

        $query = DB::table('recibos as r')
            ->join('alunos as a', 'r.aluno_id', '=', 'a.id')
            ->join('usuarios as u', 'a.usuario_id', '=', 'u.id')
            ->join('usuarios as uc', 'r.criado_por', '=', 'uc.id')
            ->leftJoin('baixas_pagamento as bp', 'r.baixa_id', '=', 'bp.id')
            ->select([
                'r.id', 'r.numero_recibo', 'r.valor_recibo', 'r.data_recibo', 'r.forma_pagamento', 'r.descricao', 'r.criado_em',
                'r.responsavel_nome', 'u.nome_completo as aluno_nome', 'r.aluno_id', 'uc.nome_completo as criado_por_nome',
                'bp.valor_principal', 'bp.valor_multa', 'bp.valor_juros',
            ])
            ->orderBy('r.criado_em', 'desc');

        if ($reciboId) {
            $query->where('r.id', (int) $reciboId);
        } elseif ($parcelaId) {
            $query->where('r.parcela_id', (int) $parcelaId);
        } elseif ($numeroRecibo) {
            $query->where('r.numero_recibo', $numeroRecibo);
        } elseif ($alunoId) {
            $query->where('r.aluno_id', (int) $alunoId);
        }

        // Responsável (não-privilegiado) só pode ver recibos dos próprios dependentes —
        // essa condição é somada a QUALQUER filtro acima, pra não dar pra "adivinhar"
        // recibo_id/parcela_id de outro aluno.
        if (!$isPrivilegiado && $isResponsavel) {
            $query->whereIn('r.aluno_id', function ($sub) use ($usuarioIdLogado) {
                $sub->select('ar.aluno_id')->from('responsaveis as resp')
                    ->join('aluno_responsavel as ar', 'resp.id', '=', 'ar.responsavel_id')
                    ->where('resp.usuario_id', $usuarioIdLogado);
            });
        }

        $recibos = $query->get()->map(function ($row) {
            $arr = (array) $row;
            $arr['valor_recibo'] = (float) $row->valor_recibo;
            $arr['valor_principal'] = $row->valor_principal !== null ? (float) $row->valor_principal : (float) $row->valor_recibo;
            $arr['valor_multa'] = (float) ($row->valor_multa ?? 0);
            $arr['valor_juros'] = (float) ($row->valor_juros ?? 0);

            return $arr;
        })->values();

        return response()->json(['success' => true, 'count' => $recibos->count(), 'data' => $recibos]);
    }
}
