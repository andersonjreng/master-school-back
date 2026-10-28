<?php

namespace App\Services\Cadastro;

use Illuminate\Support\Facades\DB;

/**
 * Regras de dados pessoais compartilhadas pelos cadastros (aluno,
 * responsável, professor, usuário): formato, dígito verificador de CPF,
 * unicidade e normalização (telefone e CEP só com dígitos, CPF formatado).
 *
 * Os métodos validar*() devolvem a primeira mensagem de erro, pronta para
 * mostrar ao usuário, ou null quando está tudo certo.
 */
class CadastroValidacaoService
{
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR',
        'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function digitos(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    /** Vazio vira null; o resto, trim. */
    public function texto($v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    public function cpfValido(?string $cpf): bool
    {
        $c = $this->digitos($cpf);
        if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $c[$i] * (($t + 1) - $i);
            }
            $dv = ((10 * $soma) % 11) % 10;
            if ((int) $c[$t] !== $dv) {
                return false;
            }
        }
        return true;
    }

    /** CPF no formato 000.000.000-00 (é como já está gravado nos responsáveis). */
    public function formatarCpf(?string $cpf): ?string
    {
        $c = $this->digitos($cpf);
        if ($c === '') {
            return null;
        }
        return strlen($c) === 11 ? substr($c, 0, 3) . '.' . substr($c, 3, 3) . '.' . substr($c, 6, 3) . '-' . substr($c, 9) : $c;
    }

    public function telefone(?string $v): ?string
    {
        $d = $this->digitos($v);
        return $d === '' ? null : $d;
    }

    public function telefoneValido(?string $v): bool
    {
        return in_array(strlen($this->digitos($v)), [10, 11], true);
    }

    public function cep(?string $v): ?string
    {
        $d = $this->digitos($v);
        return $d === '' ? null : $d;
    }

    public function emailValido(?string $v): bool
    {
        return (bool) filter_var(trim((string) $v), FILTER_VALIDATE_EMAIL);
    }

    public function emailEmUso(string $email, ?int $ignorarUsuarioId = null): bool
    {
        return DB::table('usuarios')->where('email', trim($email))
            ->when($ignorarUsuarioId, fn ($q) => $q->where('id', '!=', $ignorarUsuarioId))->exists();
    }

    /** CPF já usado em outra linha da tabela (alunos, responsaveis, professores). */
    public function cpfEmUso(string $tabela, string $cpf, ?int $ignorarId = null): bool
    {
        return DB::table($tabela)->where('cpf', $this->formatarCpf($cpf))
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))->exists();
    }

    public function validarEmail(?string $email, ?int $ignorarUsuarioId = null, bool $obrigatorio = true): ?string
    {
        if (!$this->texto($email)) {
            return $obrigatorio ? 'Informe o e-mail.' : null;
        }
        if (!$this->emailValido($email)) {
            return 'E-mail inválido.';
        }
        if ($this->emailEmUso($email, $ignorarUsuarioId)) {
            return 'Este e-mail já está em uso por outro usuário.';
        }
        return null;
    }

    public function validarSenha(?string $senha): ?string
    {
        return mb_strlen((string) $senha) < 6 ? 'A senha precisa ter pelo menos 6 caracteres.' : null;
    }

    public function validarCpf(string $tabela, ?string $cpf, bool $obrigatorio, ?int $ignorarId = null): ?string
    {
        if (!$this->texto($cpf)) {
            return $obrigatorio ? 'Informe o CPF.' : null;
        }
        if (!$this->cpfValido($cpf)) {
            return 'CPF inválido — confira os números.';
        }
        if ($this->cpfEmUso($tabela, $cpf, $ignorarId)) {
            return 'Este CPF já está cadastrado.';
        }
        return null;
    }

    public function validarTelefone(?string $tel, bool $obrigatorio): ?string
    {
        if (!$this->texto($tel)) {
            return $obrigatorio ? 'Informe o telefone (WhatsApp).' : null;
        }
        return $this->telefoneValido($tel) ? null : 'Telefone inválido — use DDD + número.';
    }

    /**
     * Endereço: com $obrigatorio, exige CEP, logradouro, número, bairro, cidade
     * e UF. Sem ele, só confere o formato do que foi preenchido.
     *
     * @param array{cep?:?string,endereco?:?string,numero?:?string,bairro?:?string,cidade?:?string,uf?:?string} $e
     */
    public function validarEndereco(array $e, bool $obrigatorio): ?string
    {
        if ($obrigatorio) {
            $rotulos = ['cep' => 'CEP', 'endereco' => 'logradouro', 'numero' => 'número', 'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'UF'];
            $faltando = array_values(array_filter($rotulos, fn ($r, $c) => !$this->texto($e[$c] ?? null), ARRAY_FILTER_USE_BOTH));
            if ($faltando) {
                return 'Endereço incompleto: falta ' . implode(', ', $faltando) . '.';
            }
        }
        if ($this->texto($e['cep'] ?? null) && strlen($this->digitos($e['cep'])) !== 8) {
            return 'CEP inválido — são 8 números.';
        }
        if ($this->texto($e['uf'] ?? null) && !in_array(strtoupper(trim($e['uf'])), self::UFS, true)) {
            return 'UF inválida.';
        }
        return null;
    }

    /** Campos de endereço normalizados, prontos para gravar. */
    public function endereco(array $e): array
    {
        return [
            'cep'         => $this->cep($e['cep'] ?? null),
            'endereco'    => $this->texto($e['endereco'] ?? null),
            'numero'      => $this->texto($e['numero'] ?? null),
            'complemento' => $this->texto($e['complemento'] ?? null),
            'bairro'      => $this->texto($e['bairro'] ?? null),
            'cidade'      => $this->texto($e['cidade'] ?? null),
            'uf'          => ($uf = $this->texto($e['uf'] ?? null)) ? strtoupper($uf) : null,
        ];
    }

    /** Erro de banco de chave única → mensagem amigável (ou null se não for isso). */
    public function mensagemDuplicidade(\Throwable $e): ?string
    {
        if (!$e instanceof \Illuminate\Database\QueryException || (int) ($e->errorInfo[1] ?? 0) !== 1062) {
            return null;
        }
        $msg = $e->getMessage();
        if (str_contains($msg, 'email')) {
            return 'Este e-mail já está em uso por outro usuário.';
        }
        if (str_contains($msg, 'cpf')) {
            return 'Este CPF já está cadastrado.';
        }
        if (str_contains($msg, 'matricula')) {
            return 'Este número de matrícula já está em uso.';
        }
        return 'Já existe um cadastro com esses dados.';
    }
}
