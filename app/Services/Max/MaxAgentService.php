<?php

namespace App\Services\Max;

use Illuminate\Support\Facades\Log;

/**
 * Equivalente ao corpo principal de api/agent/agent.php (loop de tool-calling do Max).
 *
 * Diferenca proposital em relacao ao original: aqui $usuario (id, nome, funcoes) vem
 * do payload do JWT ja validado pelo middleware VerifyLegacyJwt, nao do corpo da
 * requisicao enviado pelo front-end. O agent.php antigo confiava nesse campo vindo do
 * cliente, o que permitia manipular quais tools/permissoes eram liberadas.
 */
class MaxAgentService
{
    public function __construct(
        private AnthropicClient $claude,
        private LegacyApiClient $api,
        private ContextPrefetcher $prefetcher,
        private ToolRegistry $toolRegistry,
        private ToolExecutor $toolExecutor,
        private SystemPromptBuilder $promptBuilder,
        private UsageLimiter $limiter,
    ) {
    }

    /**
     * @return array{status: int, body: array}
     */
    public function responder(string $pergunta, array $historico, string $token, object $jwtUser): array
    {
        $tempoInicioTotal = microtime(true);

        $userId = (int) ($jwtUser->id ?? 0);
        $userName = $jwtUser->nome ?? 'Usuário';
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);

        $isAdmin = $this->hasAnyRole($funcoes, ['Administrador', 'Diretoria']);
        $isProfessor = $this->hasAnyRole($funcoes, ['Professor']);
        $isResponsavel = $this->hasAnyRole($funcoes, ['Responsavel', 'Responsável']);
        $isAluno = $this->hasAnyRole($funcoes, ['Aluno']);

        $limiteInfo = null;
        if ($userId > 0) {
            $funcao = $isAdmin ? 'Administrador' : ($isProfessor ? 'Professor' : 'Responsavel');
            $limiteInfo = $this->limiter->verificar($userId, $funcao);

            if (!$limiteInfo['permitido']) {
                Log::channel('max')->warning('Limite atingido', ['usuario_id' => $userId, 'funcao' => $funcao]);

                return [
                    'status' => 429,
                    'body' => [
                        'error'     => 'limite_atingido',
                        'message'   => 'Você atingiu o limite de mensagens do Max este mês.',
                        'uso_atual' => $limiteInfo['uso_atual'],
                        'limite'    => $limiteInfo['limite'],
                        'restantes' => 0,
                        'renovacao' => now()->addMonthNoOverflow()->startOfMonth()->format('d/m/Y'),
                    ],
                ];
            }
        }

        $dependentesPermitidos = [];
        if ($isResponsavel && $userId) {
            $dependentesPermitidos = $this->prefetcher->dependentesDoResponsavel($userId, $token);
        }

        $turmasProfessor = [];
        $alunosProfessor = [];
        $disciplinasProfessor = [];
        $unidadesLetivasIds = [];
        if ($isProfessor) {
            $contexto = $this->prefetcher->contextoDoProfessor($userId, $token);
            $turmasProfessor = $contexto['turmas'];
            $alunosProfessor = $contexto['alunos'];
            $disciplinasProfessor = $contexto['disciplinas'];
            $unidadesLetivasIds = $contexto['unidadesLetivasIds'];
        }

        $systemPrompt = $this->promptBuilder->build(
            $isAdmin, $isProfessor, $isResponsavel, $isAluno,
            $userName, $dependentesPermitidos,
            $turmasProfessor, $alunosProfessor, $disciplinasProfessor
        );

        $tools = $this->toolRegistry->paraPerfil($isAdmin, $isResponsavel);

        if ($isResponsavel && !empty($dependentesPermitidos)) {
            $systemPrompt .= "\n\nDependentes disponíveis (use estes IDs ao chamar as ferramentas): "
                . json_encode($dependentesPermitidos, JSON_UNESCAPED_UNICODE);
        }

        $messages = [];
        foreach ($historico as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $pergunta];

        for ($i = 0; $i < 5; $i++) {
            $resposta = $this->claude->enviarMensagens($messages, $tools, $systemPrompt);

            if (isset($resposta['error'])) {
                $tipoErro = $resposta['error']['type'] ?? 'unknown';
                $msgErro = $resposta['error']['message'] ?? 'Erro na API Claude';
                $msgUsuario = $tipoErro === 'overloaded_error'
                    ? 'O assistente está com muita demanda agora. Tente novamente em alguns instantes.'
                    : $msgErro;

                Log::channel('max')->error('Erro retornado pela API Claude', [
                    'usuario_id' => $userId, 'tipo' => $tipoErro, 'mensagem' => $msgErro,
                ]);

                return ['status' => 200, 'body' => ['erro' => $msgUsuario]];
            }

            $stopReason = $resposta['stop_reason'] ?? '';
            $content = $resposta['content'] ?? [];

            $contentFixado = array_map(function ($bloco) {
                if (($bloco['type'] ?? '') === 'tool_use' && is_array($bloco['input']) && empty($bloco['input'])) {
                    $bloco['input'] = new \stdClass();
                }

                return $bloco;
            }, $content);

            $messages[] = ['role' => 'assistant', 'content' => $contentFixado];

            if ($stopReason === 'end_turn') {
                $texto = '';
                foreach ($content as $bloco) {
                    if (($bloco['type'] ?? '') === 'text') {
                        $texto .= $bloco['text'];
                    }
                }

                $usoPayload = null;
                if ($limiteInfo && $userId > 0) {
                    $tokensEntrada = (int) ($resposta['usage']['input_tokens'] ?? 0);
                    $tokensSaida = (int) ($resposta['usage']['output_tokens'] ?? 0);
                    $this->limiter->registrar($userId, $tokensEntrada, $tokensSaida);

                    $novoUso = $limiteInfo['uso_atual'] + 1;
                    $usoPayload = [
                        'uso_atual'  => $novoUso,
                        'limite'     => $limiteInfo['limite'],
                        'restantes'  => max(0, $limiteInfo['restantes'] - 1),
                        'percentual' => round(($novoUso / $limiteInfo['limite']) * 100),
                    ];

                    Log::channel('max')->info('Resposta enviada com sucesso', [
                        'usuario_id'    => $userId,
                        'tokens_entrada' => $tokensEntrada,
                        'tokens_saida'   => $tokensSaida,
                        'uso_mensal'     => "$novoUso/{$limiteInfo['limite']}",
                        'tempo_total_s'  => round(microtime(true) - $tempoInicioTotal, 2),
                        'iteracoes'      => $i + 1,
                    ]);
                }

                return ['status' => 200, 'body' => ['resposta' => $texto, 'uso' => $usoPayload]];
            }

            if ($stopReason === 'tool_use') {
                $toolResults = [];
                foreach ($content as $bloco) {
                    if (($bloco['type'] ?? '') === 'tool_use') {
                        Log::channel('max')->info('Tool chamada', [
                            'usuario_id' => $userId, 'tool' => $bloco['name'], 'input' => $bloco['input'] ?? [],
                        ]);

                        $resultado = $this->toolExecutor->executar(
                            $bloco['name'],
                            $bloco['input'] ?? [],
                            $token,
                            $isResponsavel,
                            $dependentesPermitidos,
                            $isProfessor,
                            $alunosProfessor,
                            $disciplinasProfessor,
                            $turmasProfessor,
                            $unidadesLetivasIds
                        );

                        if (isset($resultado['erro'])) {
                            Log::channel('max')->warning('Tool retornou erro', [
                                'usuario_id' => $userId, 'tool' => $bloco['name'], 'erro' => $resultado['erro'],
                            ]);
                        }

                        $toolResults[] = [
                            'type'        => 'tool_result',
                            'tool_use_id' => $bloco['id'],
                            'content'     => json_encode($resultado, JSON_UNESCAPED_UNICODE),
                        ];
                    }
                }
                $messages[] = ['role' => 'user', 'content' => $toolResults];
                continue;
            }

            break;
        }

        Log::channel('max')->error('Loop encerrado sem resposta final', ['usuario_id' => $userId]);

        return ['status' => 200, 'body' => ['erro' => 'Não foi possível processar a pergunta. Tente novamente.']];
    }

    private function hasAnyRole(array $roles, array $allowed): bool
    {
        foreach ($allowed as $role) {
            if (in_array($role, $roles, true)) {
                return true;
            }
        }

        return false;
    }
}
