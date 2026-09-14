<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/admin/{vinculos_professor_turma_disciplina,
 * vinculos_professor_turma_disciplina_v2,delete_vinculo_professor_turma_disciplina}.php.
 *
 * Duas telas diferentes de gerenciar o mesmo vínculo turma_professor_disciplina:
 * porTurma() edita as disciplinas/professores de UMA turma (payload fixa turma_id,
 * varia disciplina); porProfessor() edita as turmas/disciplinas de UM professor
 * (payload fixa professor_id, varia turma+disciplina). Ambas usam upsert (ON
 * DUPLICATE KEY) sobre a PK composta (turma_id, disciplina_id).
 */
class VinculoProfessorTurmaDisciplinaController extends Controller
{
    public function porTurma(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas Administradores podem gerenciar vínculos de disciplinas e turmas.'], 403);
        }

        $turmaId = $request->input('turma_id');
        $disciplinasLote = $request->input('disciplinas', []);

        if (!$turmaId || !is_numeric($turmaId)) {
            return response()->json(['error' => 'ID da turma (turma_id) é obrigatório e deve ser numérico.'], 400);
        }
        if (empty($disciplinasLote) || !is_array($disciplinasLote)) {
            return response()->json(['error' => 'O campo "disciplinas" deve ser um array não vazio.'], 400);
        }
        $turmaId = (int) $turmaId;

        $itens = [];
        $erros = [];
        foreach ($disciplinasLote as $vinculo) {
            $disciplinaId = $vinculo['disciplina_id'] ?? null;
            if (!$disciplinaId || !is_numeric($disciplinaId)) {
                $erros[] = "Vínculo inválido: ID da disciplina ($disciplinaId) deve ser numérico.";
                continue;
            }

            $professorId = $vinculo['professor_id'] ?? null;
            $itens[] = [
                'turma_id' => $turmaId,
                'disciplina_id' => (int) $disciplinaId,
                'professor_id' => ($professorId !== null && is_numeric($professorId) && (int) $professorId > 0) ? (int) $professorId : null,
                'carga_horaria_semanal' => (int) ($vinculo['carga_horaria_semanal'] ?? 0),
            ];
        }

        if (!empty($erros)) {
            return response()->json([
                'success' => false,
                'error' => 'Falha no processamento do lote devido a erros de execução. Verifique a lista.',
                'turma_id' => $turmaId,
                'resumo_processamento' => ['criados' => 0, 'atualizados' => 0, 'erros' => $erros],
            ], 400);
        }

        $resultados = $this->upsertVinculos($itens);

        return response()->json([
            'success' => true,
            'message' => $this->montarMensagem($resultados, count($disciplinasLote)),
            'turma_id' => $turmaId,
            'resumo_processamento' => $resultados,
        ]);
    }

    public function porProfessor(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado. Apenas Administradores podem gerenciar vínculos de disciplinas e turmas.'], 403);
        }

        $professorIdRaiz = $request->input('professor_id');
        $vinculosLote = $request->input('vinculos', []);

        if ($professorIdRaiz !== null && !is_numeric($professorIdRaiz)) {
            return response()->json(['error' => 'ID do professor (professor_id) deve ser numérico ou nulo (para desvincular).'], 400);
        }
        if (empty($vinculosLote) || !is_array($vinculosLote)) {
            return response()->json(['error' => 'O campo "vinculos" deve ser um array não vazio.'], 400);
        }
        $professorIdRaiz = ($professorIdRaiz !== null && (int) $professorIdRaiz > 0) ? (int) $professorIdRaiz : null;

        $itens = [];
        $erros = [];
        foreach ($vinculosLote as $vinculo) {
            $turmaId = $vinculo['turma_id'] ?? null;
            $disciplinaId = $vinculo['disciplina_id'] ?? null;

            if (!$disciplinaId || !is_numeric($disciplinaId) || !$turmaId || !is_numeric($turmaId)) {
                $erros[] = "Vínculo inválido: IDs de disciplina ($disciplinaId) e turma ($turmaId) são obrigatórios e devem ser numéricos.";
                continue;
            }

            $itens[] = [
                'turma_id' => (int) $turmaId,
                'disciplina_id' => (int) $disciplinaId,
                'professor_id' => $professorIdRaiz,
                'carga_horaria_semanal' => (int) ($vinculo['carga_horaria_semanal'] ?? 0),
            ];
        }

        if (!empty($erros)) {
            return response()->json([
                'success' => false,
                'error' => 'Falha no processamento do lote devido a erros de execução. Verifique a lista.',
                'resumo_processamento' => ['professor_id_aplicado' => $professorIdRaiz ?? 'NULL (Desvínculo)', 'criados' => 0, 'atualizados' => 0, 'erros' => $erros],
            ], 400);
        }

        $resultados = $this->upsertVinculos($itens);
        $resultados = ['professor_id_aplicado' => $professorIdRaiz ?? 'NULL (Desvínculo)'] + $resultados;

        return response()->json([
            'success' => true,
            'message' => $this->montarMensagem($resultados, count($vinculosLote)),
            'resumo_processamento' => $resultados,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $turmaId = $request->input('turma_id');
        $disciplinaId = $request->input('disciplina_id');

        if (!$turmaId || !$disciplinaId) {
            return response()->json(['error' => 'Os campos turma_id e disciplina_id são obrigatórios.'], 400);
        }

        $existe = DB::table('turma_professor_disciplina')
            ->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)->exists();
        if (!$existe) {
            return response()->json(['error' => 'Vínculo não encontrado.'], 404);
        }

        DB::table('turma_professor_disciplina')->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)->delete();

        return response()->json(['success' => true, 'message' => 'Vínculo removido com sucesso.']);
    }

    /**
     * @param array<int, array{turma_id:int, disciplina_id:int, professor_id:?int, carga_horaria_semanal:int}> $itens
     * @return array{criados:int, atualizados:int, erros:array}
     */
    private function upsertVinculos(array $itens): array
    {
        $criados = 0;
        $atualizados = 0;
        $erros = [];

        foreach ($itens as $item) {
            $existia = DB::table('turma_professor_disciplina')
                ->where('turma_id', $item['turma_id'])->where('disciplina_id', $item['disciplina_id'])
                ->first(['professor_id', 'carga_horaria_semanal']);

            DB::statement('
                INSERT INTO turma_professor_disciplina (turma_id, disciplina_id, professor_id, carga_horaria_semanal)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE professor_id = VALUES(professor_id), carga_horaria_semanal = VALUES(carga_horaria_semanal)
            ', [$item['turma_id'], $item['disciplina_id'], $item['professor_id'], $item['carga_horaria_semanal']]);

            if (!$existia) {
                $criados++;
            } elseif ((int) $existia->professor_id !== (int) $item['professor_id'] || (int) $existia->carga_horaria_semanal !== $item['carga_horaria_semanal']) {
                $atualizados++;
            }
            // Se existia e nada mudou, não conta como criado nem atualizado — igual ao legado (affected_rows = 0).
        }

        return compact('criados', 'atualizados', 'erros');
    }

    private function montarMensagem(array $resultados, int $totalEnviado): string
    {
        $totalProcessado = $resultados['criados'] + $resultados['atualizados'];
        $naoAlterados = $totalEnviado - $totalProcessado;

        $mensagem = 'Processamento concluído. ';
        if ($totalProcessado > 0) {
            $mensagem .= "{$resultados['criados']} vínculos criados e {$resultados['atualizados']} atualizados.";
        }
        if ($naoAlterados > 0) {
            $mensagem .= " ($naoAlterados itens já estavam com os mesmos valores no banco de dados).";
        } elseif ($totalProcessado === 0) {
            $mensagem .= 'Nenhum vínculo novo ou alterado foi processado.';
        }

        return $mensagem;
    }
}
