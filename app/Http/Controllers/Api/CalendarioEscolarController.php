<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use App\Services\Turmas\CalendarioEscolarService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Parametrizações → Calendário Escolar: feriados, recessos, sábados letivos
 * e eventos do ano letivo. Leitura para todos os perfis logados; alteração
 * só para a gestão (Administrador/Diretoria).
 */
class CalendarioEscolarController extends Controller
{
    public function __construct(private CalendarioEscolarService $calendario, private LogSistemaService $log) {}

    public function show(int $ano): JsonResponse
    {
        if ($ano < 2000 || $ano > 2100) {
            return response()->json(['error' => 'Ano inválido.'], 400);
        }
        return response()->json(['success' => true, 'data' => $this->calendario->resumo($ano)]);
    }

    public function store(Request $request, int $ano): JsonResponse
    {
        $dados = $this->validar($request, $ano);
        if ($dados instanceof JsonResponse) {
            return $dados;
        }
        $id = DB::table('calendario_escolar')->insertGetId($dados + ['ano_letivo' => $ano, 'origem' => 'manual']);
        $this->registrar($request, 'CALENDARIO_MARCACAO_CRIADA', 'POST', "Calendário $ano: {$dados['descricao']} ({$dados['tipo']})", ['id' => $id] + $dados);

        return response()->json(['success' => true, 'data' => $this->calendario->resumo($ano)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $atual = DB::table('calendario_escolar')->where('id', $id)->first();
        if (!$atual) {
            return response()->json(['error' => 'Marcação não encontrada.'], 404);
        }
        $dados = $this->validar($request, (int) $atual->ano_letivo);
        if ($dados instanceof JsonResponse) {
            return $dados;
        }
        DB::table('calendario_escolar')->where('id', $id)->update($dados);
        $this->registrar($request, 'CALENDARIO_MARCACAO_ALTERADA', 'PUT', "Calendário {$atual->ano_letivo}: {$dados['descricao']} ({$dados['tipo']})", ['id' => $id] + $dados);

        return response()->json(['success' => true, 'data' => $this->calendario->resumo((int) $atual->ano_letivo)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $atual = DB::table('calendario_escolar')->where('id', $id)->first();
        if (!$atual) {
            return response()->json(['error' => 'Marcação não encontrada.'], 404);
        }
        DB::table('calendario_escolar')->where('id', $id)->delete();
        $this->registrar($request, 'CALENDARIO_MARCACAO_EXCLUIDA', 'DELETE', "Calendário {$atual->ano_letivo}: {$atual->descricao} removido", ['id' => $id]);

        return response()->json(['success' => true, 'data' => $this->calendario->resumo((int) $atual->ano_letivo)]);
    }

    public function importarNacionais(Request $request, int $ano): JsonResponse
    {
        $criados = $this->calendario->importarNacionais($ano);
        $this->registrar($request, 'CALENDARIO_FERIADOS_IMPORTADOS', 'POST', "Calendário $ano: $criados feriado(s) nacional(is) importado(s)", ['ano' => $ano]);

        return response()->json([
            'success' => true,
            'message' => $criados ? "$criados feriado(s) nacional(is) adicionado(s)." : 'Todos os feriados nacionais já estavam no calendário.',
            'data'    => $this->calendario->resumo($ano),
        ]);
    }

    /** @return array|JsonResponse campos validados da marcação */
    private function validar(Request $request, int $ano)
    {
        $tipo = (string) $request->input('tipo');
        $descricao = trim((string) $request->input('descricao'));
        $sigla = mb_strtoupper(trim((string) $request->input('sigla', '')));
        $inicio = (string) $request->input('data_inicio');
        $fim = (string) ($request->input('data_fim') ?: $inicio);

        if (!in_array($tipo, CalendarioEscolarService::TIPOS, true)) {
            return response()->json(['error' => 'Tipo inválido.'], 422);
        }
        if ($descricao === '' || mb_strlen($descricao) > 150) {
            return response()->json(['error' => 'Informe uma descrição (até 150 caracteres).'], 422);
        }
        try {
            $ini = Carbon::createFromFormat('Y-m-d', $inicio)->startOfDay();
            $f = Carbon::createFromFormat('Y-m-d', $fim)->startOfDay();
        } catch (\Throwable) {
            return response()->json(['error' => 'Datas inválidas.'], 422);
        }
        if ($f->lt($ini)) {
            return response()->json(['error' => 'A data final não pode ser antes da inicial.'], 422);
        }
        if ($ini->year !== $ano || $f->year !== $ano) {
            return response()->json(['error' => "As datas precisam estar dentro de $ano."], 422);
        }
        if (mb_strlen($sigla) > 4) {
            return response()->json(['error' => 'A sigla pode ter até 4 caracteres.'], 422);
        }
        if ($tipo === 'sabado_letivo') {
            $temSabado = false;
            for ($d = $ini->copy(); $d->lte($f); $d->addDay()) {
                $temSabado = $temSabado || $d->isSaturday();
            }
            if (!$temSabado) {
                return response()->json(['error' => 'Sábado letivo precisa incluir pelo menos um sábado.'], 422);
            }
        }

        return [
            'tipo' => $tipo, 'descricao' => $descricao, 'sigla' => $sigla !== '' ? $sigla : null,
            'data_inicio' => $ini->toDateString(), 'data_fim' => $f->toDateString(),
        ];
    }

    private function registrar(Request $request, string $acao, string $metodo, string $descricao, array $dados): void
    {
        $user = $request->attributes->get('max_user');
        $this->log->registrar($request, (int) ($user->id ?? 0), $user->nome ?? 'desconhecido', $acao, '/api/calendario', $metodo, $descricao, 200, $dados);
    }
}
