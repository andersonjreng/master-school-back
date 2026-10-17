<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Equivalente a api/whatsapp/whatsapp_helper.php + whatsapp_avaliacoes_helper.php.
 *
 * Consolida todas as avaliações lançadas/alteradas hoje pra um mesmo aluno numa
 * ÚNICA mensagem por responsável, e avisa sobre avaliações agendadas/criadas hoje.
 * Idempotente: cada avaliação só entra em UMA mensagem, controlado pelas UNIQUE
 * keys de notificacoes_whatsapp_enviadas / notificacoes_whatsapp_avaliacoes_enviadas
 * (responsavel_id, aluno_id, avaliacao_id) — reserva atômica via insertOrIgnore().
 */
class WhatsappNotificacaoService
{
    /**
     * @return array{enviados:int, ignorados:int, falhas:int, detalhes:array}
     */
    public function processarNotificacoesNotas(): array
    {
        $enviados = 0;
        $ignorados = 0;
        $falhas = 0;
        $detalhes = [];

        $hoje = now()->toDateString();

        $notas = DB::table('notas as n')
            ->join('avaliacoes as a', 'n.avaliacao_id', '=', 'a.id')
            ->join('disciplinas as d', 'a.disciplina_id', '=', 'd.id')
            ->leftJoin('tipos_avaliacao as t', 'a.tipo_avaliacao_id', '=', 't.id')
            ->where(function ($q) use ($hoje) {
                $q->whereDate('n.criado_em', $hoje)->orWhereDate('n.atualizado_em', $hoje);
            })
            ->get(['n.aluno_id', 'n.avaliacao_id', 'a.descricao as avaliacao_descricao', 't.nome_tipo', 'd.nome_disciplina']);

        // Agrupa as avaliações de hoje por aluno (um aluno pode ter mais de uma nota lançada no dia).
        $avaliacoesPorAluno = [];
        foreach ($notas as $nota) {
            $alunoId = (int) $nota->aluno_id;
            $avaliacaoId = (int) $nota->avaliacao_id;
            // Descrição é opcional (observação); sem ela, identifica pelo tipo.
            $label = trim(($nota->nome_disciplina ?? '') . ' - ' . (($nota->avaliacao_descricao ?? '') ?: ($nota->nome_tipo ?? '')), ' -');

            $avaliacoesPorAluno[$alunoId][$avaliacaoId] = $label;
        }

        if (empty($avaliacoesPorAluno)) {
            return ['enviados' => 0, 'ignorados' => 0, 'falhas' => 0, 'detalhes' => []];
        }

        foreach ($avaliacoesPorAluno as $alunoId => $avaliacoesDoAluno) {
            $responsaveis = $this->buscarResponsaveisElegiveisDoAluno($alunoId);

            foreach ($responsaveis as $resp) {
                $responsavelId = (int) $resp->responsavel_id;
                $telefone = $resp->telefone;

                // Reserva atômica de cada avaliação individualmente: só entra na mensagem
                // consolidada quem ainda não tinha sido notificado sobre aquela avaliação
                // específica (nesta ou em execução anterior).
                $avaliacoesReservadas = [];
                foreach ($avaliacoesDoAluno as $avaliacaoId => $label) {
                    $inseriu = DB::table('notificacoes_whatsapp_enviadas')->insertOrIgnore([
                        'responsavel_id' => $responsavelId,
                        'aluno_id' => $alunoId,
                        'avaliacao_id' => $avaliacaoId,
                        'telefone' => $telefone,
                        'status' => 'pendente',
                    ]);
                    if ($inseriu > 0) {
                        $avaliacoesReservadas[$avaliacaoId] = $label;
                    }
                }

                if (empty($avaliacoesReservadas)) {
                    $ignorados++;
                    $detalhes[] = $this->montarDetalhe($responsavelId, $resp, $alunoId, array_values($avaliacoesDoAluno), $telefone, 'ja_notificado', null);
                    continue;
                }

                $listaAvaliacoes = $this->formatarListaAvaliacoes(array_values($avaliacoesReservadas));
                $to = $this->formatarTelefone($telefone);

                $envio = $this->enviar($to, [$resp->nome_responsavel, $resp->nome_aluno, $listaAvaliacoes]);
                $status = $envio['sucesso'] ? 'enviado' : 'falha';
                $envio['sucesso'] ? $enviados++ : $falhas++;

                DB::table('notificacoes_whatsapp_enviadas')
                    ->where('responsavel_id', $responsavelId)
                    ->where('aluno_id', $alunoId)
                    ->whereIn('avaliacao_id', array_keys($avaliacoesReservadas))
                    ->update(['status' => $status, 'wamid' => $envio['wamid'], 'erro' => $envio['erro']]);

                $detalhes[] = $this->montarDetalhe($responsavelId, $resp, $alunoId, array_values($avaliacoesReservadas), $telefone, $status, $envio['erro']);
            }
        }

        return compact('enviados', 'ignorados', 'falhas', 'detalhes');
    }

    /**
     * @return array{enviados:int, ignorados:int, falhas:int, detalhes:array}
     */
    public function processarAvisosAvaliacaoAgendada(): array
    {
        $enviados = 0;
        $ignorados = 0;
        $falhas = 0;
        $detalhes = [];

        $hoje = now()->toDateString();

        $avaliacoes = DB::table('avaliacoes as a')
            ->join('disciplinas as d', 'a.disciplina_id', '=', 'd.id')
            ->leftJoin('tipos_avaliacao as t', 'a.tipo_avaliacao_id', '=', 't.id')
            ->whereDate('a.data_cadastro', $hoje)
            ->get(['a.id as avaliacao_id', 'a.turma_id', 'a.data_aplicacao', 'a.descricao', 't.nome_tipo', 'd.nome_disciplina']);

        if ($avaliacoes->isEmpty()) {
            return ['enviados' => 0, 'ignorados' => 0, 'falhas' => 0, 'detalhes' => []];
        }

        foreach ($avaliacoes as $avaliacao) {
            $avaliacaoId = (int) $avaliacao->avaliacao_id;
            $turmaId = (int) $avaliacao->turma_id;
            $avaliacaoLabel = trim(($avaliacao->nome_disciplina ?? '') . ' - ' . (($avaliacao->descricao ?? '') ?: ($avaliacao->nome_tipo ?? '')), ' -');
            $dataFormatada = $avaliacao->data_aplicacao ? date('d/m/Y', strtotime($avaliacao->data_aplicacao)) : 'a definir';

            $responsaveis = $this->buscarResponsaveisElegiveisDaTurma($turmaId);

            foreach ($responsaveis as $resp) {
                $responsavelId = (int) $resp->responsavel_id;
                $alunoId = (int) $resp->aluno_id;
                $telefone = $resp->telefone;

                $inseriu = DB::table('notificacoes_whatsapp_avaliacoes_enviadas')->insertOrIgnore([
                    'responsavel_id' => $responsavelId,
                    'aluno_id' => $alunoId,
                    'avaliacao_id' => $avaliacaoId,
                    'telefone' => $telefone,
                    'status' => 'pendente',
                ]);

                if ($inseriu === 0) {
                    $ignorados++;
                    $detalhes[] = $this->montarDetalhe($responsavelId, $resp, $alunoId, [$avaliacaoLabel], $telefone, 'ja_notificado', null);
                    continue;
                }

                $to = $this->formatarTelefone($telefone);
                $envio = $this->enviar(
                    $to,
                    [$resp->nome_responsavel, $resp->nome_aluno, $avaliacaoLabel, $dataFormatada],
                    config('whatsapp.template_name_avaliacao')
                );
                $status = $envio['sucesso'] ? 'enviado' : 'falha';
                $envio['sucesso'] ? $enviados++ : $falhas++;

                DB::table('notificacoes_whatsapp_avaliacoes_enviadas')
                    ->where('responsavel_id', $responsavelId)
                    ->where('aluno_id', $alunoId)
                    ->where('avaliacao_id', $avaliacaoId)
                    ->update(['status' => $status, 'wamid' => $envio['wamid'], 'erro' => $envio['erro']]);

                $detalhes[] = $this->montarDetalhe($responsavelId, $resp, $alunoId, ["$avaliacaoLabel ($dataFormatada)"], $telefone, $status, $envio['erro']);
            }
        }

        return compact('enviados', 'ignorados', 'falhas', 'detalhes');
    }

    /**
     * Avisos da Central de Comunicação (mural, recados e ocorrências) para os
     * responsáveis com WhatsApp ativo. Só roda com o template configurado
     * (WHATSAPP_TEMPLATE_NAME_COMUNICACAO). Não avisa o que a família já leu
     * no sistema, e cada item é avisado uma única vez por responsável
     * (comunicacao_whatsapp_enviados).
     *
     * - mural: comunicados para famílias publicados nas últimas 48 h;
     * - recado: mensagem da escola não lida há mais de 10 minutos;
     * - ocorrência: ocorrência compartilhada com a família nas últimas 48 h.
     *
     * @return array{ativo: bool, enviados: int, ignorados: int, falhas: int}
     */
    public function processarAvisosComunicacao(): array
    {
        $template = (string) config('whatsapp.template_name_comunicacao');
        $saida = ['ativo' => $template !== '', 'enviados' => 0, 'ignorados' => 0, 'falhas' => 0];
        if (!$template) {
            return $saida;
        }
        $desde = now()->subHours(48);
        $portal = (string) config('whatsapp.portal_url');

        $avisar = function (string $tipo, int $ref, object $resp, string $oque, string $sobre) use ($template, $portal, &$saida) {
            $ja = DB::table('comunicacao_whatsapp_enviados')->where('tipo', $tipo)->where('referencia_id', $ref)->where('responsavel_id', $resp->responsavel_id)->exists();
            if ($ja) {
                $saida['ignorados']++;
                return;
            }
            $r = $this->enviar($this->formatarTelefone($resp->telefone), [$resp->nome_responsavel, $oque, $sobre, $portal ?: 'o portal da escola'], $template);
            DB::table('comunicacao_whatsapp_enviados')->insertOrIgnore([
                'tipo' => $tipo, 'referencia_id' => $ref, 'responsavel_id' => $resp->responsavel_id,
                'status' => $r['sucesso'] ? 'enviado' : 'falha', 'erro' => $r['erro'] ? mb_substr($r['erro'], 0, 255) : null,
            ]);
            $r['sucesso'] ? $saida['enviados']++ : $saida['falhas']++;
        };

        // Mural: comunicados para as famílias (escola, familias ou turmas).
        $mural = app(\App\Services\Comunicacao\MuralService::class);
        $posts = DB::table('mural_posts')->where('criado_em', '>=', $desde)->where('oculto', 0)->whereIn('publico', ['escola', 'familias', 'turmas'])->get();
        foreach ($posts as $post) {
            $familias = $mural->destinatarios($post)->where('grupo', 'Família')->pluck('usuario_id')->all();
            if (!$familias) {
                continue;
            }
            $lidos = DB::table('mural_leituras')->where('post_id', $post->id)->pluck('usuario_id')->flip();
            $resps = DB::table('responsaveis as r')->join('usuarios as u', 'u.id', '=', 'r.usuario_id')
                ->whereIn('r.usuario_id', $familias)->where('r.notificacao_whatsapp_ativa', 1)->whereNotNull('r.telefone')->where('r.telefone', '!=', '')
                ->get(['r.id as responsavel_id', 'r.usuario_id', 'r.telefone', 'u.nome_completo as nome_responsavel']);
            foreach ($resps as $resp) {
                if (!isset($lidos[$resp->usuario_id])) {
                    $avisar('mural', (int) $post->id, $resp, 'um novo comunicado', '"' . mb_substr($post->titulo, 0, 60) . '"');
                }
            }
        }

        // Recados: última mensagem da escola em cada conversa, não lida há 10+ min.
        $msgs = DB::table('recados_mensagens as m')->join('recados_conversas as c', 'c.id', '=', 'm.conversa_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'm.autor_usuario_id')
            ->where('m.lado', 'escola')->whereBetween('m.criado_em', [$desde, now()->subMinutes(10)])
            ->whereIn('m.id', DB::table('recados_mensagens')->where('lado', 'escola')->groupBy('conversa_id')->selectRaw('MAX(id)'))
            ->get(['m.id', 'm.conversa_id', 'c.aluno_id', 'c.escola_usuario_id', 'u.nome_completo as autor']);
        foreach ($msgs as $m) {
            foreach ($this->buscarResponsaveisElegiveisDoAluno((int) $m->aluno_id) as $resp) {
                $usuario = DB::table('responsaveis')->where('id', $resp->responsavel_id)->value('usuario_id');
                $leu = DB::table('recados_leituras')->where('conversa_id', $m->conversa_id)->where('usuario_id', $usuario)->value('ultima_lida_id');
                if ((int) $leu >= (int) $m->id) {
                    continue;
                }
                $quem = $m->escola_usuario_id ? 'um recado de ' . $m->autor : 'um recado da secretaria';
                $avisar('recado', (int) $m->id, $resp, $quem, $resp->nome_aluno);
            }
        }

        // Ocorrências compartilhadas com a família.
        $ocorrencias = DB::table('ocorrencias')->where('visibilidade', 'familia')->where('criado_em', '>=', $desde)->whereNull('ciente_em')->get(['id', 'aluno_id', 'tipo']);
        foreach ($ocorrencias as $o) {
            foreach ($this->buscarResponsaveisElegiveisDoAluno((int) $o->aluno_id) as $resp) {
                $avisar('ocorrencia', (int) $o->id, $resp, $o->tipo === 'elogio' ? 'um elogio' : 'um registro de ocorrência', $resp->nome_aluno);
            }
        }

        return $saida;
    }

    private function buscarResponsaveisElegiveisDoAluno(int $alunoId): \Illuminate\Support\Collection
    {
        return DB::table('aluno_responsavel as ar')
            ->join('responsaveis as r', 'ar.responsavel_id', '=', 'r.id')
            ->join('usuarios as u', 'r.usuario_id', '=', 'u.id')
            ->join('alunos as al', 'ar.aluno_id', '=', 'al.id')
            ->join('usuarios as ua', 'al.usuario_id', '=', 'ua.id')
            ->where('ar.aluno_id', $alunoId)
            ->where('r.notificacao_whatsapp_ativa', 1)
            ->whereNotNull('r.telefone')
            ->where('r.telefone', '!=', '')
            ->get(['r.id as responsavel_id', 'r.telefone', 'u.nome_completo as nome_responsavel', 'ua.nome_completo as nome_aluno']);
    }

    private function buscarResponsaveisElegiveisDaTurma(int $turmaId): \Illuminate\Support\Collection
    {
        return DB::table('turma_aluno as ta')
            ->join('alunos as al', 'ta.aluno_id', '=', 'al.id')
            ->join('usuarios as ua', 'al.usuario_id', '=', 'ua.id')
            ->join('aluno_responsavel as ar', 'al.id', '=', 'ar.aluno_id')
            ->join('responsaveis as r', 'ar.responsavel_id', '=', 'r.id')
            ->join('usuarios as ur', 'r.usuario_id', '=', 'ur.id')
            ->where('ta.turma_id', $turmaId)
            ->where('r.notificacao_whatsapp_ativa', 1)
            ->whereNotNull('r.telefone')
            ->where('r.telefone', '!=', '')
            ->get(['al.id as aluno_id', 'ua.nome_completo as nome_aluno', 'r.id as responsavel_id', 'r.telefone', 'ur.nome_completo as nome_responsavel']);
    }

    private function montarDetalhe(int $responsavelId, object $resp, int $alunoId, array $avaliacoes, string $telefone, string $status, ?string $erro): array
    {
        return [
            'responsavel_id' => $responsavelId,
            'nome_responsavel' => $resp->nome_responsavel,
            'aluno_id' => $alunoId,
            'nome_aluno' => $resp->nome_aluno,
            'avaliacoes' => $avaliacoes,
            'telefone' => $telefone,
            'status' => $status,
            'erro' => $erro,
        ];
    }

    /**
     * Formata uma lista de avaliações em texto corrido, no estilo "A, B e C".
     * Uma avaliação só retorna sem conectivo nenhum.
     */
    private function formatarListaAvaliacoes(array $labels): string
    {
        $labels = array_values(array_unique($labels));
        $total = count($labels);

        if ($total === 0) {
            return '';
        }
        if ($total === 1) {
            return $labels[0];
        }

        $ultimo = array_pop($labels);

        return implode(', ', $labels) . ' e ' . $ultimo;
    }

    private function formatarTelefone(string $telefone): string
    {
        $digitos = preg_replace('/\D/', '', $telefone);
        if (strlen($digitos) <= 11) {
            $digitos = '55' . $digitos;
        }

        return $digitos;
    }

    /**
     * @return array{sucesso:bool, wamid:?string, erro:?string}
     */
    private function enviar(string $to, array $parametros, ?string $templateName = null): array
    {
        $token = config('whatsapp.token');
        if (!$token) {
            return ['sucesso' => false, 'wamid' => null, 'erro' => 'WHATSAPP_TOKEN não configurado no servidor (.env).'];
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            config('whatsapp.api_version'),
            config('whatsapp.phone_number_id')
        );

        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName ?? config('whatsapp.template_name'),
                'language' => ['code' => config('whatsapp.template_lang')],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($texto) => ['type' => 'text', 'text' => (string) $texto], $parametros),
                ]],
            ],
        ];

        try {
            $response = Http::withToken($token)->timeout(15)->post($url, $body);
        } catch (\Throwable $e) {
            return ['sucesso' => false, 'wamid' => null, 'erro' => $e->getMessage()];
        }

        if ($response->successful()) {
            return ['sucesso' => true, 'wamid' => $response->json('messages.0.id'), 'erro' => null];
        }

        return ['sucesso' => false, 'wamid' => null, 'erro' => $response->body()];
    }
}
