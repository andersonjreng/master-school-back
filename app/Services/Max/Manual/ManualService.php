<?php

namespace App\Services\Max\Manual;

use App\Services\Sistema\PermissaoService;
use App\Support\Papeis;
use App\Support\Permissoes;

/**
 * Entrega o ManualSistema ao Max respeitando quem pergunta: o índice vai no
 * prompt (curto) e o passo a passo vem pela ferramenta consultar_manual.
 * O que o perfil não acessa aparece só como "existe, mas sem acesso", para
 * o Max nunca dizer que uma função não existe.
 */
class ManualService
{
    public function __construct(private PermissaoService $permissoes)
    {
    }

    /**
     * @return array{0: array, 1: array} [visíveis, sem acesso]
     */
    public function separar(array $funcoes, bool $isProfessor): array
    {
        $efetivas = Papeis::ehAdministrador($funcoes) ? null : $this->permissoes->efetivas($funcoes);
        $visiveis = [];
        $semAcesso = [];
        foreach (ManualSistema::itens() as $item) {
            if ($this->podeVer($item['acesso'], $funcoes, $efetivas, $isProfessor)) {
                $visiveis[] = $item;
            } else {
                $semAcesso[] = $item;
            }
        }
        return [$visiveis, $semAcesso];
    }

    /** Bloco do prompt: o que existe e onde fica, para este usuário. */
    public function blocoPrompt(array $funcoes, bool $isProfessor): string
    {
        [$visiveis, $semAcesso] = $this->separar($funcoes, $isProfessor);

        $indice = [];
        foreach ($visiveis as $i) {
            $indice[] = "- {$i['titulo']} — {$i['menu']}";
        }
        $outros = array_map(fn ($i) => $i['titulo'], $semAcesso);

        return "SUPORTE AO USUÁRIO (sua função principal, além de consultar dados):\n"
            . "Você é o suporte do MasterSchool. Para QUALQUER pergunta de 'como faço', 'onde fica', 'dá para…', 'o sistema tem…',\n"
            . "chame a ferramenta consultar_manual ANTES de responder e explique com base no que ela trouxer: diga o caminho do menu\n"
            . "e os passos de forma curta e numerada. Nunca diga que algo não existe ou não é possível sem consultar o manual.\n"
            . "Se o manual disser que a função existe mas o perfil do usuário não tem acesso, explique isso usando o texto de 'como_liberar'\n"
            . "(com o nome exato da permissão). Não acrescente passos ou recursos que o manual não citou.\n"
            . "Se o manual realmente não tiver o assunto, diga que não encontrou e sugira falar com a secretaria.\n\n"
            . "O QUE ESTE USUÁRIO PODE USAR (título — onde fica):\n" . implode("\n", $indice)
            . ($outros ? "\n\nEXISTE NO SISTEMA, MAS ESTE PERFIL NÃO TEM ACESSO: " . implode('; ', $outros) . '.' : '');
    }

    public static function ferramenta(): array
    {
        return [
            'name'        => 'consultar_manual',
            'description' => 'Manual do MasterSchool: onde fica e como fazer cada coisa no sistema (passo a passo, caminho do menu e quem pode usar). '
                . 'Use em toda pergunta de "como faço", "onde fica", "dá para", "o sistema tem". Informe palavras-chave do assunto.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'assunto' => ['type' => 'string', 'description' => 'O que o usuário quer fazer ou encontrar, ex.: "planejamento de aula", "dar baixa em mensalidade", "rematrícula".'],
                ],
                'required' => ['assunto'],
            ],
        ];
    }

    /** Resultado da ferramenta: até 3 itens que o usuário pode usar e, se for o caso, os que existem sem acesso. */
    public function consultar(string $assunto, array $funcoes, bool $isProfessor): array
    {
        [$visiveis, $semAcesso] = $this->separar($funcoes, $isProfessor);
        $termos = $this->termos($assunto);
        if (!$termos) {
            return ['erro' => 'Informe o assunto da dúvida.'];
        }

        $ranquear = function (array $itens) use ($termos) {
            $pontos = [];
            foreach ($itens as $k => $i) {
                $p = $this->pontuar($i, $termos);
                if ($p > 0) {
                    $pontos[$k] = $p;
                }
            }
            arsort($pontos);
            return array_map(fn ($k) => $itens[$k] + ['_pontos' => $pontos[$k]], array_keys($pontos));
        };

        // Corta o ruído: só o que pontua perto do melhor resultado (e um mínimo absoluto).
        $todos = $ranquear($visiveis);
        $melhor = $todos[0]['_pontos'] ?? 0;
        $achados = array_slice(array_filter($todos, fn ($i) => $i['_pontos'] >= max(3, $melhor * 0.5)), 0, 3);
        $melhor = $achados[0]['_pontos'] ?? 0;
        // "Existe, mas sem acesso" só faz sentido para a equipe (família não opera o financeiro da escola).
        $bloqueados = Papeis::ehEquipe($funcoes) ? array_slice($ranquear($semAcesso), 0, 2) : [];

        $resultado = [
            'encontrado' => array_map(fn ($i) => [
                'titulo' => $i['titulo'], 'onde_fica' => $i['menu'], 'como_fazer' => $i['passos'],
            ], $achados),
        ];
        // Só menciona o que está fora do perfil quando combina tão bem quanto (ou melhor que) o achado.
        $relevantes = array_filter($bloqueados, fn ($i) => $i['_pontos'] >= max(3, $melhor));
        if ($relevantes) {
            $resultado['existe_sem_acesso'] = array_values(array_map(fn ($i) => [
                'titulo' => $i['titulo'], 'onde_fica' => $i['menu'],
                'como_liberar' => $this->comoLiberar($i['acesso']),
            ], $relevantes));
        }
        if (!$achados && !$relevantes) {
            $resultado['aviso'] = 'Nada no manual sobre esse assunto. Diga que não encontrou e sugira falar com a secretaria da escola.';
        }
        return $resultado;
    }

    /** Texto exato do que liberar (nomes como aparecem na tela Permissões). */
    private function comoLiberar(array $acesso): string
    {
        $rotulos = array_map(fn ($p) => '"' . (Permissoes::CATALOGO[$p][1] ?? $p) . '"', $acesso['pode'] ?? []);
        if ($rotulos) {
            return 'Existe no sistema, mas o perfil deste usuário não tem acesso. O Administrador pode liberar em Administração > Permissões, '
                . 'ligando a permissão ' . implode(' ou ', $rotulos) . ' para o perfil dele.';
        }
        $perfis = implode(', ', $acesso['perfis'] ?? []);
        return "Existe no sistema, mas é só para o perfil: $perfis.";
    }

    private function podeVer(array $acesso, array $funcoes, ?array $efetivas, bool $isProfessor): bool
    {
        if (!$acesso) {
            return true;
        }
        if ($efetivas === null) {
            return true; // Administrador
        }
        if (!empty($acesso['professor']) && $isProfessor) {
            return true;
        }
        if (!empty($acesso['perfis']) && array_intersect($acesso['perfis'], $funcoes)) {
            return true;
        }
        if (!empty($acesso['pode']) && array_intersect($acesso['pode'], $efetivas)) {
            return true;
        }
        return false;
    }

    private function pontuar(array $item, array $termos): int
    {
        $titulo = $this->normalizar($item['titulo']);
        $palavras = $this->normalizar($item['palavras'] . ' ' . $item['menu']);
        $passos = $this->normalizar($item['passos']);
        $p = 0;
        foreach ($termos as $t) {
            if (str_contains($titulo, $t)) {
                $p += 3;
            }
            if (str_contains($palavras, $t)) {
                $p += 2;
            }
            if (str_contains($passos, $t)) {
                $p += 1;
            }
        }
        return $p;
    }

    /**
     * Radicais das palavras (3+ letras, sem acento e sem palavras vazias):
     * "professora" → "professo" casa com "professor"; "falo" → "fal" casa com "falar".
     */
    private function termos(string $texto): array
    {
        $vazias = ['como', 'faco', 'fazer', 'onde', 'fica', 'para', 'que', 'uma', 'umas', 'uns', 'dos', 'das', 'com', 'sistema', 'masterschool',
            'quero', 'posso', 'pode', 'tem', 'tela', 'menu', 'qual', 'quais', 'minha', 'meu', 'meus', 'minhas', 'nao', 'sim', 'esta', 'isso'];
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', $this->normalizar($texto)) as $w) {
            if (strlen($w) < 3 || in_array($w, $vazias, true)) {
                continue;
            }
            $n = strlen($w);
            $out[] = substr($w, 0, max(3, $n <= 5 ? $n - 1 : $n - 2));
        }
        return array_values(array_unique($out));
    }

    private function normalizar(string $t): string
    {
        $t = mb_strtolower($t);
        return strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'º' => 'o', 'ª' => 'a']);
    }
}
