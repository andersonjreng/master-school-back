<?php

namespace App\Services\Max;

/**
 * Equivalente ao bloco de montagem de $tools em api/agent/agent.php.
 */
class ToolRegistry
{
    public function paraPerfil(bool $isAdmin, bool $isResponsavel): array
    {
        $tools = [];

        if ($isResponsavel) {
            $tools[] = [
                'name'        => 'buscar_frequencia_aluno',
                'description' => 'Retorna o histórico de frequência de um dos seus dependentes.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'aluno_id' => ['type' => 'integer', 'description' => 'ID do aluno dependente.'],
                        'ano'      => ['type' => 'string',  'description' => 'Ano letivo, ex: 2026.'],
                    ],
                    'required' => ['aluno_id'],
                ],
            ];
            $tools[] = [
                'name'        => 'buscar_notas_aluno',
                'description' => 'Retorna as notas de um dos seus dependentes.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'aluno_id' => ['type' => 'integer', 'description' => 'ID do aluno dependente.'],
                    ],
                    'required' => ['aluno_id'],
                ],
            ];
            $tools[] = [
                'name'        => 'buscar_proximas_avaliacoes',
                'description' => 'Retorna as próximas avaliações (provas, trabalhos, etc.) de um dos seus dependentes. Use quando o responsável perguntar sobre provas, avaliações ou calendário escolar do filho.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'aluno_id' => ['type' => 'integer', 'description' => 'ID do aluno dependente. Obrigatório quando há mais de um dependente.'],
                        'dias'     => ['type' => 'integer', 'description' => 'Quantos dias à frente buscar. Padrão: 30.'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'buscar_parcelas_aluno',
                'description' => 'Retorna as parcelas/mensalidades de um dos seus dependentes: valores, vencimentos, status (pago, pendente, vencido) e encargos por atraso.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'aluno_id' => ['type' => 'integer', 'description' => 'ID do aluno dependente.'],
                    ],
                    'required' => ['aluno_id'],
                ],
            ];
            $tools[] = [
                'name'        => 'consultar_documento_aluno',
                'description' => 'Consulta os dados de um documento institucional de um dos seus dependentes (declaração de matrícula, comprovante anual de pagamentos ou histórico escolar). Não gera o PDF — apenas os dados; a emissão oficial acontece na tela Documentos.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'aluno_id' => ['type' => 'integer', 'description' => 'ID do aluno dependente.'],
                        'tipo'     => [
                            'type'        => 'string',
                            'enum'        => ['declaracao_matricula', 'comprovante_pagamentos', 'historico_escolar'],
                            'description' => 'Tipo de documento.',
                        ],
                        'ano' => ['type' => 'integer', 'description' => 'Ano de referência, usado apenas para comprovante_pagamentos (padrão: ano atual).'],
                    ],
                    'required' => ['aluno_id', 'tipo'],
                ],
            ];

            return $tools;
        }

        $tools[] = [
            'name'        => 'buscar_alunos',
            'description' => 'Retorna a lista de alunos com turma, responsável e status.',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
        ];

        if ($isAdmin) {
            $tools[] = [
                'name'        => 'buscar_turmas',
                'description' => 'Retorna todas as turmas com disciplinas e professores vinculados.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            ];
            $tools[] = [
                'name'        => 'buscar_professores',
                'description' => 'Retorna a lista de professores com suas turmas vinculadas.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            ];
            $tools[] = [
                'name'        => 'buscar_parcelas_aluno',
                'description' => 'Retorna as parcelas/mensalidades de um aluno específico: valores, vencimentos, status (pendente, pago, vencido, cancelado, negociado) e encargos por atraso.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'aluno_id' => ['type' => 'integer', 'description' => 'ID do aluno. Use buscar_alunos se não souber.'],
                        'status'   => ['type' => 'string', 'description' => 'Filtra por status: pendente, pago, vencido, cancelado ou negociado (opcional).'],
                    ],
                    'required' => ['aluno_id'],
                ],
            ];
            $tools[] = [
                'name'        => 'buscar_inadimplencia',
                'description' => 'Retorna as parcelas vencidas (inadimplência) da escola. Pode filtrar por turma e/ou por aluno específico.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'turma_id' => ['type' => 'integer', 'description' => 'Filtra por turma (opcional).'],
                        'aluno_id' => ['type' => 'integer', 'description' => 'Filtra por aluno específico (opcional).'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'buscar_plano_financeiro_aluno',
                'description' => 'Retorna o plano de pagamento (matrícula financeira) de um aluno: nome do plano, valor total, parcelas pagas/vencidas/canceladas.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'aluno_id' => ['type' => 'integer', 'description' => 'ID do aluno. Use buscar_alunos se não souber.'],
                    ],
                    'required' => ['aluno_id'],
                ],
            ];
            $tools[] = [
                'name'        => 'consultar_documento_aluno',
                'description' => 'Consulta os dados de um documento institucional de um aluno (declaração de matrícula, comprovante anual de pagamentos ou histórico escolar). Não gera o PDF — apenas os dados; a emissão oficial acontece na tela Documentos.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'aluno_id' => ['type' => 'integer', 'description' => 'ID do aluno. Use buscar_alunos se não souber.'],
                        'tipo'     => [
                            'type'        => 'string',
                            'enum'        => ['declaracao_matricula', 'comprovante_pagamentos', 'historico_escolar'],
                            'description' => 'Tipo de documento.',
                        ],
                        'ano' => ['type' => 'integer', 'description' => 'Ano de referência, usado apenas para comprovante_pagamentos (padrão: ano atual).'],
                    ],
                    'required' => ['aluno_id', 'tipo'],
                ],
            ];
        }

        $tools[] = [
            'name'        => 'buscar_frequencia_aluno',
            'description' => 'Retorna o histórico de frequência de um aluno.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'aluno_id' => ['type' => 'integer', 'description' => 'ID do aluno. Use buscar_alunos se não souber.'],
                    'ano'      => ['type' => 'string',  'description' => 'Ano letivo, ex: 2026.'],
                ],
                'required' => ['aluno_id'],
            ],
        ];
        $tools[] = [
            'name'        => 'buscar_notas_aluno',
            'description' => 'Retorna as notas e médias de um aluno por disciplina.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'aluno_id' => ['type' => 'integer', 'description' => 'ID do aluno. Use buscar_alunos se não souber.'],
                ],
                'required' => ['aluno_id'],
            ],
        ];
        $tools[] = [
            'name'        => 'buscar_proximas_avaliacoes',
            'description' => 'Retorna as próximas avaliações (provas, trabalhos, testes, etc.) agendadas. Use quando perguntarem sobre provas marcadas, calendário de avaliações ou quando é a próxima prova de uma turma ou disciplina.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'turma_id'   => ['type' => 'integer', 'description' => 'Filtra por turma específica (opcional).'],
                    'disciplina' => ['type' => 'string',  'description' => 'Nome da disciplina para filtrar no resultado (opcional).'],
                    'dias'       => ['type' => 'integer', 'description' => 'Quantos dias à frente buscar. Padrão: 45.'],
                ],
            ],
        ];

        return $tools;
    }
}
