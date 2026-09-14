<?php

namespace App\Services\Max;

use App\Services\Frequencia\ResumoGeralService;

/**
 * Equivalente a executarFerramenta() em api/agent/agent.php.
 */
class ToolExecutor
{
    public function __construct(
        private LegacyApiClient $api,
        private ResumoGeralService $resumoGeral,
    ) {
    }

    public function executar(
        string $nome,
        array $input,
        string $token,
        bool $isResponsavel,
        array $dependentesPermitidos,
        bool $isProfessor,
        array $alunosProfessor,
        array $disciplinasProfessor,
        array $turmasProfessor,
        array $unidadesLetivasIds
    ): array {
        if (in_array($nome, ['buscar_frequencia_aluno', 'buscar_notas_aluno', 'buscar_parcelas_aluno', 'consultar_documento_aluno'], true)) {
            $alunoId = (int) ($input['aluno_id'] ?? 0);

            if ($isResponsavel) {
                $idsPermitidos = array_column($dependentesPermitidos, 'aluno_id');
                if (!in_array($alunoId, $idsPermitidos, true)) {
                    return ['erro' => 'Acesso negado: este aluno não está vinculado ao seu cadastro de responsável.'];
                }
            }

            if ($isProfessor && !empty($alunosProfessor)) {
                $idsPermitidos = array_column($alunosProfessor, 'aluno_id');
                if (!in_array($alunoId, $idsPermitidos, true)) {
                    return ['erro' => 'Acesso negado: este aluno não pertence a nenhuma das suas turmas.'];
                }
            }
        }

        return match ($nome) {
            'buscar_alunos' => $this->buscarAlunos($token, $isProfessor, $alunosProfessor),
            'buscar_turmas' => $this->api->get('/admin/get_turmas_detalhes.php', [], $token),
            'buscar_professores' => $this->api->get('/professores/get_professores.php', [], $token),
            'buscar_frequencia_aluno' => $this->buscarFrequenciaAluno($input, $token),
            'buscar_frequencia_geral' => $this->buscarFrequenciaGeral($input),
            'buscar_notas_aluno' => $this->buscarNotasAluno($input, $token, $isProfessor, $alunosProfessor, $disciplinasProfessor, $unidadesLetivasIds),
            'buscar_proximas_avaliacoes' => $this->buscarProximasAvaliacoes($input, $token, $isResponsavel, $dependentesPermitidos, $isProfessor, $turmasProfessor),
            'buscar_parcelas_aluno' => $this->buscarParcelasAluno($input, $token, $isResponsavel),
            'buscar_inadimplencia' => $this->buscarInadimplencia($input, $token),
            'buscar_plano_financeiro_aluno' => $this->api->get('/financeiro/get_matriculas_financeiras.php', ['aluno_id' => (int) ($input['aluno_id'] ?? 0)], $token),
            'consultar_documento_aluno' => $this->consultarDocumentoAluno($input, $token),
            default => ['erro' => "Ferramenta '$nome' não reconhecida"],
        };
    }

    private function buscarAlunos(string $token, bool $isProfessor, array $alunosProfessor): array
    {
        $resultado = $this->api->get('/alunos/get_alunos_detalhes.php', [], $token);

        if ($isProfessor && !empty($alunosProfessor)) {
            $idsPermitidos = array_column($alunosProfessor, 'aluno_id');
            $lista = $resultado['data'] ?? (isset($resultado[0]) ? $resultado : []);
            $lista = array_values(array_filter($lista, fn ($a) => in_array((int) ($a['aluno_id'] ?? $a['id'] ?? 0), $idsPermitidos, true)));

            return ['data' => $lista];
        }

        return $resultado;
    }

    private function buscarFrequenciaAluno(array $input, string $token): array
    {
        return $this->api->get('/professores/get_historico_frequencia.php', [
            'aluno_id' => (int) ($input['aluno_id'] ?? 0),
            'ano'      => $input['ano'] ?? date('Y'),
        ], $token);
    }

    /**
     * Frequência agregada por turma, direto no banco local (o módulo de
     * frequência já foi migrado pra cá) — evita precisar de uma chamada por
     * turma dentro do loop de tools, que tem teto de iterações.
     */
    private function buscarFrequenciaGeral(array $input): array
    {
        $ano = (int) ($input['ano'] ?? date('Y'));
        $mes = isset($input['mes']) ? (int) $input['mes'] : null;

        $porTurma = $this->resumoGeral->porTurma($ano, $mes);
        $abaixoDaMeta = array_values(array_filter($porTurma, fn ($t) => $t['abaixo_da_meta']));

        return [
            'ano' => $ano,
            'mes' => $mes,
            'turmas' => $porTurma,
            'turmas_abaixo_da_meta' => $abaixoDaMeta,
        ];
    }

    private function buscarNotasAluno(array $input, string $token, bool $isProfessor, array $alunosProfessor, array $disciplinasProfessor, array $unidadesLetivasIds): array
    {
        $id = (int) ($input['aluno_id'] ?? 0);

        if ($isProfessor && !empty($disciplinasProfessor)) {
            $turmaId = 0;
            foreach ($alunosProfessor as $a) {
                if ($a['aluno_id'] === $id) {
                    $turmaId = $a['turma_id'];
                    break;
                }
            }
            if (!$turmaId) {
                return ['erro' => 'Aluno não encontrado nas suas turmas.'];
            }

            $discsNaTurma = array_values(array_filter($disciplinasProfessor, fn ($d) => $d['turma_id'] === $turmaId));
            $uIds = !empty($unidadesLetivasIds) ? $unidadesLetivasIds : range(1, 4);

            $notasPorDisc = [];
            foreach ($discsNaTurma as $disc) {
                $dId = $disc['disciplina_id'];
                $bimestres = [];
                foreach ($uIds as $uId) {
                    $ar = $this->api->get('/avaliacoes/get_notas_por_turma_disciplina.php', [
                        'turma_id' => $turmaId, 'disciplina_id' => $dId, 'unidade_id' => $uId,
                    ], $token);
                    foreach ($ar['alunos'] ?? [] as $aluno) {
                        if ((int) ($aluno['aluno_id'] ?? 0) === $id) {
                            $bimestres[] = [
                                'unidade_id' => $uId,
                                'avaliacoes' => $ar['avaliacoes'] ?? [],
                                'notas'      => $aluno['notas'] ?? [],
                                'media'      => $aluno['media_bimestral'] ?? null,
                            ];
                            break;
                        }
                    }
                }
                $notasPorDisc[] = [
                    'disciplina_id' => $dId,
                    'disciplina'    => $disc['nome'],
                    'bimestres'     => $bimestres,
                ];
            }

            return ['aluno_id' => $id, 'disciplinas' => $notasPorDisc];
        }

        return $this->api->get('/avaliacoes/get_notas_por_aluno.php', ['aluno_id' => $id], $token);
    }

    private function buscarProximasAvaliacoes(array $input, string $token, bool $isResponsavel, array $dependentesPermitidos, bool $isProfessor, array $turmasProfessor): array
    {
        $dias = max(1, (int) ($input['dias'] ?? 45));
        $inicio = date('Y-m-d');
        $fim = date('Y-m-d', strtotime("+{$dias} days"));
        $discFiltro = strtolower(trim($input['disciplina'] ?? ''));

        if ($isResponsavel) {
            $alunoId = (int) ($input['aluno_id'] ?? 0);
            $alvos = $alunoId
                ? array_filter($dependentesPermitidos, fn ($d) => $d['aluno_id'] === $alunoId)
                : $dependentesPermitidos;

            $resultados = [];
            foreach ($alvos as $dep) {
                $turmaIdDep = (int) ($dep['turma_id'] ?? 0);

                if (!$turmaIdDep) {
                    $notasResp = $this->api->get('/avaliacoes/get_notas_por_aluno.php', ['aluno_id' => $dep['aluno_id']], $token);
                    $turmaIdDep = (int) ($notasResp['turma_id'] ?? $notasResp['data'][0]['turma_id'] ?? 0);
                }

                if (!$turmaIdDep) {
                    continue;
                }

                $avResp = $this->api->get('/avaliacoes/get_avaliacoes.php', [
                    'turma_id' => $turmaIdDep, 'data_inicio' => $inicio, 'data_fim' => $fim,
                ], $token);
                $lista = $avResp['data'] ?? (isset($avResp[0]) ? $avResp : []);

                if ($discFiltro) {
                    $lista = array_values(array_filter($lista, fn ($a) => str_contains(strtolower($a['nome_disciplina'] ?? ''), $discFiltro)));
                }

                if (!empty($lista)) {
                    $resultados[] = ['aluno' => $dep['nome'], 'avaliacoes' => $lista];
                }
            }

            return empty($resultados)
                ? ['mensagem' => "Nenhuma avaliação encontrada nos próximos $dias dias.", 'data' => []]
                : ['data' => $resultados];
        }

        if ($isProfessor) {
            $turmaIdFiltro = (int) ($input['turma_id'] ?? 0);
            $turmasAlvo = $turmaIdFiltro
                ? array_filter($turmasProfessor, fn ($t) => $t['turma_id'] === $turmaIdFiltro)
                : $turmasProfessor;

            if ($turmaIdFiltro && empty($turmasAlvo)) {
                return ['erro' => 'Esta turma não pertence ao seu cadastro.'];
            }

            $resultados = [];
            foreach ($turmasAlvo as $turma) {
                $avResp = $this->api->get('/avaliacoes/get_avaliacoes.php', [
                    'turma_id' => $turma['turma_id'], 'data_inicio' => $inicio, 'data_fim' => $fim,
                ], $token);
                $lista = $avResp['data'] ?? (isset($avResp[0]) ? $avResp : []);

                if ($discFiltro) {
                    $lista = array_values(array_filter($lista, fn ($a) => str_contains(strtolower($a['nome_disciplina'] ?? ''), $discFiltro)));
                }

                foreach ($lista as $av) {
                    $av['nome_turma'] = $av['nome_turma'] ?? $turma['nome_turma'];
                    $resultados[] = $av;
                }
            }

            usort($resultados, fn ($a, $b) => strcmp($a['data_aplicacao'] ?? '', $b['data_aplicacao'] ?? ''));

            return empty($resultados)
                ? ['mensagem' => "Nenhuma avaliação encontrada nos próximos $dias dias.", 'data' => []]
                : ['data' => $resultados];
        }

        $turmaIdFiltro = (int) ($input['turma_id'] ?? 0);
        $query = ['data_inicio' => $inicio, 'data_fim' => $fim];
        if ($turmaIdFiltro) {
            $query['turma_id'] = $turmaIdFiltro;
        }
        $avResp = $this->api->get('/avaliacoes/get_avaliacoes.php', $query, $token);
        $lista = $avResp['data'] ?? (isset($avResp[0]) ? $avResp : []);

        if ($discFiltro) {
            $lista = array_values(array_filter($lista, fn ($a) => str_contains(strtolower($a['nome_disciplina'] ?? ''), $discFiltro)));
        }

        return ['data' => $lista, 'total' => count($lista)];
    }

    private function buscarParcelasAluno(array $input, string $token, bool $isResponsavel): array
    {
        $id = (int) ($input['aluno_id'] ?? 0);

        if ($isResponsavel) {
            return $this->api->get('/financeiro/get_minhas_parcelas.php', ['aluno_id' => $id], $token);
        }

        $status = trim($input['status'] ?? '');
        $query = ['aluno_id' => $id];
        if ($status !== '') {
            $query['status'] = $status;
        }

        return $this->api->get('/financeiro/get_contas_receber.php', $query, $token);
    }

    private function buscarInadimplencia(array $input, string $token): array
    {
        $turmaId = (int) ($input['turma_id'] ?? 0);
        $alunoIdFiltro = (int) ($input['aluno_id'] ?? 0);

        $query = $turmaId ? ['turma_id' => $turmaId] : [];
        $resultado = $this->api->get('/financeiro/get_inadimplencia.php', $query, $token);

        if ($alunoIdFiltro) {
            $lista = array_values(array_filter($resultado['data'] ?? [], fn ($p) => (int) ($p['aluno_id'] ?? 0) === $alunoIdFiltro));

            return ['data' => $lista, 'count' => count($lista)];
        }

        return $resultado;
    }

    private function consultarDocumentoAluno(array $input, string $token): array
    {
        $id = (int) ($input['aluno_id'] ?? 0);
        $tipo = $input['tipo'] ?? '';

        return match ($tipo) {
            'declaracao_matricula' => $this->api->get('/documentos/get_declaracao_matricula.php', ['aluno_id' => $id], $token),
            'comprovante_pagamentos' => $this->api->get('/documentos/get_comprovante_pagamentos.php', [
                'aluno_id' => $id, 'ano' => (int) ($input['ano'] ?? date('Y')),
            ], $token),
            'historico_escolar' => $this->api->get('/documentos/get_historico_escolar.php', ['aluno_id' => $id], $token),
            default => ['erro' => "Tipo de documento '$tipo' não reconhecido. Use declaracao_matricula, comprovante_pagamentos ou historico_escolar."],
        };
    }
}
