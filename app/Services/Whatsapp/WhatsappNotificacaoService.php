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
            ->where(function ($q) use ($hoje) {
                $q->whereDate('n.criado_em', $hoje)->orWhereDate('n.atualizado_em', $hoje);
            })
            ->get(['n.aluno_id', 'n.avaliacao_id', 'a.descricao as avaliacao_descricao', 'd.nome_disciplina']);

        // Agrupa as avaliações de hoje por aluno (um aluno pode ter mais de uma nota lançada no dia).
        $avaliacoesPorAluno = [];
        foreach ($notas as $nota) {
            $alunoId = (int) $nota->aluno_id;
            $avaliacaoId = (int) $nota->avaliacao_id;
            $label = trim(($nota->nome_disciplina ?? '') . ' - ' . ($nota->avaliacao_descricao ?? ''), ' -');

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
            ->whereDate('a.data_cadastro', $hoje)
            ->get(['a.id as avaliacao_id', 'a.turma_id', 'a.data_aplicacao', 'a.descricao', 'd.nome_disciplina']);

        if ($avaliacoes->isEmpty()) {
            return ['enviados' => 0, 'ignorados' => 0, 'falhas' => 0, 'detalhes' => []];
        }

        foreach ($avaliacoes as $avaliacao) {
            $avaliacaoId = (int) $avaliacao->avaliacao_id;
            $turmaId = (int) $avaliacao->turma_id;
            $avaliacaoLabel = trim(($avaliacao->nome_disciplina ?? '') . ' - ' . ($avaliacao->descricao ?? ''), ' -');
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
