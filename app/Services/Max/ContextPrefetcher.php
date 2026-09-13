<?php

namespace App\Services\Max;

/**
 * Equivalente aos blocos de "Pré-fetch: Responsável" e "Pré-fetch: Professor"
 * em api/agent/agent.php.
 */
class ContextPrefetcher
{
    public function __construct(private LegacyApiClient $api)
    {
    }

    public function dependentesDoResponsavel(int $userId, string $token): array
    {
        $resp = $this->api->get('/responsaveis/get_dependentes.php', ['usuario_id' => $userId], $token);
        $lista = $resp['dependentes'] ?? $resp['data'] ?? (is_array($resp) && isset($resp[0]) ? $resp : []);

        $dependentes = [];
        foreach ($lista as $dep) {
            $id = $dep['aluno_id'] ?? $dep['id'] ?? null;
            $nome = $dep['nome_completo'] ?? $dep['nome_aluno'] ?? $dep['nome'] ?? '';
            $turmaId = (int) ($dep['turma_id'] ?? $dep['id_turma'] ?? 0);
            if ($id) {
                $dependentes[] = ['aluno_id' => (int) $id, 'nome' => $nome, 'turma_id' => $turmaId];
            }
        }

        return $dependentes;
    }

    /**
     * @return array{turmas: array, alunos: array, disciplinas: array, unidadesLetivasIds: array}
     */
    public function contextoDoProfessor(int $userId, string $token): array
    {
        $tempoInicio = microtime(true);

        $unidadesLetivasIds = [];
        $uResp = $this->api->get('/avaliacoes/get_unidades_letivas.php', [], $token);
        foreach ($uResp['data'] ?? (isset($uResp[0]) ? $uResp : []) as $u) {
            $uId = (int) ($u['id'] ?? 0);
            if ($uId) {
                $unidadesLetivasIds[] = $uId;
            }
        }

        $turmasProfessor = [];
        $alunosProfessor = [];
        $disciplinasProfessor = [];

        $resp = $this->api->get('/professores/get_minhas_turmas.php', [], $token);
        $turmas = $resp['turmas'] ?? $resp['data'] ?? (isset($resp[0]) ? $resp : []);

        foreach ($turmas as $turma) {
            $turmaId = (int) ($turma['turma_id'] ?? $turma['id'] ?? 0);
            $nomeTurma = $turma['nome_turma'] ?? $turma['nome'] ?? '';
            if (!$turmaId) {
                continue;
            }

            // Corta o prefetch em 20s pra não deixar o professor sem resposta.
            if ((microtime(true) - $tempoInicio) > 20) {
                break;
            }

            $turmasProfessor[] = ['turma_id' => $turmaId, 'nome_turma' => $nomeTurma];

            $dr = $this->api->get('/professores/get_minhas_disciplinas.php', ['turma_id' => $turmaId], $token);
            $discs = $dr['disciplinas'] ?? $dr['data'] ?? (isset($dr[0]) ? $dr : []);

            foreach ($discs as $disc) {
                $discId = (int) ($disc['id'] ?? $disc['disciplina_id'] ?? 0);
                $discNome = $disc['nome'] ?? $disc['nome_disciplina'] ?? '';
                if (!$discId) {
                    continue;
                }
                $disciplinasProfessor[] = ['disciplina_id' => $discId, 'nome' => $discNome, 'turma_id' => $turmaId];
            }

            $ar = $this->api->get('/professores/get_alunos_por_turma.php', ['turma_id' => $turmaId], $token);
            $alunos = $ar['alunos'] ?? $ar['data'] ?? (isset($ar[0]) ? $ar : []);

            foreach ($alunos as $aluno) {
                $alunoId = (int) ($aluno['aluno_id'] ?? $aluno['id'] ?? 0);
                $nomeAluno = $aluno['nome_completo'] ?? $aluno['nome_aluno'] ?? $aluno['nome'] ?? '';
                $jaExiste = !empty(array_filter($alunosProfessor, fn ($a) => $a['aluno_id'] === $alunoId));
                if ($alunoId && !$jaExiste) {
                    $alunosProfessor[] = ['aluno_id' => $alunoId, 'nome' => $nomeAluno, 'turma_id' => $turmaId];
                }
            }
        }

        return [
            'turmas'              => $turmasProfessor,
            'alunos'              => $alunosProfessor,
            'disciplinas'         => $disciplinasProfessor,
            'unidadesLetivasIds'  => $unidadesLetivasIds,
        ];
    }
}
