<?php

namespace App\Services\Comunicacao;

use App\Services\Documentos\EscolaConfigService;
use App\Services\Max\AnthropicClient;
use App\Services\Max\UsageLimiter;

/**
 * "Escreve pra mim": o Max transforma o rascunho (às vezes só tópicos) num
 * comunicado ou numa ocorrência bem escritos. A pessoa revisa e decide se usa;
 * nada é publicado por aqui. Conta no limite mensal do Max de quem pede.
 */
class MaxEscreveService
{
    public const TIPOS = ['comunicado', 'ocorrencia'];
    public const AJUSTES = ['curto' => 'Deixe mais curto e direto.', 'formal' => 'Use um tom mais formal.',
        'acolhedor' => 'Use um tom mais acolhedor e próximo das famílias.'];

    public function __construct(private AnthropicClient $claude, private UsageLimiter $limiter)
    {
    }

    /**
     * @return array{titulo: string, texto: string}
     * @throws \DomainException limite do mês ou rascunho vazio
     * @throws \RuntimeException o Max não respondeu
     */
    public function escrever(PerfilComunicacao $p, string $tipo, string $titulo, string $texto, array $contexto, ?string $ajuste): array
    {
        if (trim($titulo . $texto) === '') {
            throw new \DomainException('Escreva ao menos uma ideia (ex.: "reunião de pais sábado 9h, pauta: notas") que o Max desenvolve.');
        }
        $funcao = $p->gestor ? 'Administrador' : 'Professor';
        if (!($this->limiter->verificar($p->usuarioId, $funcao)['permitido'] ?? true)) {
            throw new \DomainException('Você atingiu o limite mensal de uso do Max.');
        }

        $msg = "Rascunho:\nTítulo: " . mb_substr(trim($titulo), 0, 200) . "\nTexto: " . mb_substr(trim($texto), 0, 4000);
        foreach ($contexto as $k => $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                $msg .= "\n" . ucfirst($k) . ': ' . mb_substr((string) $v, 0, 200);
            }
        }
        if ($ajuste && isset(self::AJUSTES[$ajuste])) {
            $msg .= "\n\nAjuste pedido: " . self::AJUSTES[$ajuste];
        }

        $r = $this->claude->gerarTexto($this->prompt($tipo), $msg, 900);
        $this->limiter->registrar($p->usuarioId, $r['tokens_entrada'], $r['tokens_saida']);

        $json = preg_match('/\{.*\}/s', $r['texto'], $m) ? json_decode($m[0], true) : null;
        if (!is_array($json) || trim((string) ($json['texto'] ?? '')) === '') {
            throw new \RuntimeException('O Max não conseguiu escrever agora. Tente de novo.');
        }
        return [
            'titulo' => mb_substr(trim((string) ($json['titulo'] ?? $titulo)), 0, $tipo === 'ocorrencia' ? 150 : 200),
            'texto' => mb_substr(trim((string) $json['texto']), 0, 8000),
        ];
    }

    private function prompt(string $tipo): string
    {
        $escola = app(EscolaConfigService::class)->obter()['nome_escola'] ?? 'a escola';
        $comum = "Você é o Max, assistente da escola \"$escola\". Escreva em português do Brasil, claro e cordial, sem jargão.\n"
            . "Regras: não invente fatos, datas, horários, locais, valores nem nomes que não estejam no rascunho. Se faltar uma informação "
            . "essencial, deixe um marcador entre colchetes para a escola completar, ex.: [horário]. Não use Markdown (nada de **, # ou listas "
            . "com *); quebras de linha simples são permitidas. Responda somente com um JSON: {\"titulo\": \"...\", \"texto\": \"...\"}.\n";

        return $comum . match ($tipo) {
            'ocorrencia' => "Tarefa: registro de ocorrência de um aluno, que a família lê no app. Descreva o que foi observado de forma "
                . "objetiva e respeitosa, sem rótulos ou julgamentos sobre a criança, sem diagnósticos e sem comparar com colegas. Se for "
                . "um elogio, valorize o comportamento concreto. Se houver encaminhamento (o que a escola fará ou pede à família), inclua. "
                . "Título com até 80 caracteres, descrevendo o fato sem rótulos (ex.: \"Empurrou um colega no recreio\", nunca "
                . "\"Agressão\" ou \"Mau comportamento\"); texto com até 90 palavras. Não cite outros alunos pelo nome.",
            default => "Tarefa: comunicado para o mural da escola (famílias e/ou equipe). Título curto e informativo (até 60 caracteres). "
                . "Texto com até 120 palavras: comece pelo essencial (o quê, quando, onde), depois o que a família precisa fazer. "
                . "Feche com uma linha de agradecimento ou contato, sem assinatura.",
        };
    }
}
