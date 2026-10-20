<?php

namespace App\Services\Financeiro\Asaas;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Credenciais da conta Asaas da escola (tabela asaas_configuracao, linha única).
 *
 * A chave da API e o token do webhook ficam criptografados com a APP_KEY e
 * nunca saem do back-end: para a tela vai só a chave mascarada.
 */
class AsaasConfigService
{
    public const AMBIENTES = ['sandbox', 'producao'];

    /** Prefixo de cada tipo de chave — evita colar a chave de produção no sandbox e vice-versa. */
    private const PREFIXOS = ['sandbox' => '$aact_hmlg_', 'producao' => '$aact_prod_'];

    /** Dados para a tela (sem segredos, exceto o token do webhook, que o admin cadastra no painel do Asaas). */
    public function resumo(): array
    {
        $row = $this->linha();
        $chave = $this->decifrar($row->api_key ?? null);

        return [
            'ambiente'        => $row->ambiente ?? 'sandbox',
            'tem_chave'       => $chave !== null,
            'chave_mascarada' => $chave ? $this->mascarar($chave) : null,
            'webhook_token'   => $this->decifrar($row->webhook_token ?? null),
            'conta_nome'      => $row->conta_nome ?? null,
            'conta_documento' => $row->conta_documento ?? null,
            'conta_email'     => $row->conta_email ?? null,
            'testado_em'      => $row->testado_em ?? null,
        ];
    }

    public function ambiente(): string
    {
        return $this->linha()->ambiente ?? 'sandbox';
    }

    /** Chave da API em texto puro — só para o AsaasClient. */
    public function apiKey(): ?string
    {
        return $this->decifrar($this->linha()->api_key ?? null);
    }

    public function webhookToken(): ?string
    {
        return $this->decifrar($this->linha()->webhook_token ?? null);
    }

    /**
     * Salva ambiente e (opcionalmente) uma chave nova. Chave vazia = mantém a atual.
     * Trocar a chave ou o ambiente apaga os dados da conta validada antes.
     *
     * @throws \InvalidArgumentException
     */
    public function salvar(string $ambiente, ?string $novaChave, int $usuarioId): void
    {
        if (!in_array($ambiente, self::AMBIENTES, true)) {
            throw new \InvalidArgumentException('Ambiente do Asaas inválido.');
        }
        $novaChave = $novaChave !== null ? trim($novaChave) : null;
        if ($novaChave === '') {
            $novaChave = null;
        }

        $chaveFinal = $novaChave ?? $this->apiKey();
        if ($chaveFinal !== null && !str_starts_with($chaveFinal, self::PREFIXOS[$ambiente])) {
            $outro = $ambiente === 'sandbox' ? 'produção' : 'sandbox';
            throw new \InvalidArgumentException(
                "Esta chave não é do ambiente " . ($ambiente === 'sandbox' ? 'sandbox' : 'de produção') .
                " (deveria começar com " . self::PREFIXOS[$ambiente] . "). Parece ser uma chave de $outro."
            );
        }

        $row = $this->linha();
        $mudouConta = $novaChave !== null || ($row && $row->ambiente !== $ambiente);

        $dados = [
            'ambiente'       => $ambiente,
            'atualizado_por' => $usuarioId,
        ];
        if ($novaChave !== null) {
            $dados['api_key'] = Crypt::encryptString($novaChave);
        }
        if ($mudouConta) {
            $dados += ['conta_nome' => null, 'conta_documento' => null, 'conta_email' => null, 'testado_em' => null];
        }
        if (!$row || !$row->webhook_token) {
            // Token que o Asaas vai mandar no header asaas-access-token de cada webhook.
            $dados['webhook_token'] = Crypt::encryptString(Str::random(48));
        }

        DB::table('asaas_configuracao')->updateOrInsert(['id' => 1], $dados);
    }

    /** Guarda os dados da conta confirmados pelo "Testar conexão". */
    public function registrarConta(array $conta): void
    {
        DB::table('asaas_configuracao')->where('id', 1)->update([
            'conta_nome'      => $conta['nome'],
            'conta_documento' => $conta['documento'],
            'conta_email'     => $conta['email'],
            'testado_em'      => now(),
        ]);
    }

    private function linha(): ?object
    {
        // O back é um só para todas as escolas: banco de escola que ainda não
        // rodou a migration do Asaas se comporta como "Asaas não configurado".
        if (!DB::getSchemaBuilder()->hasTable('asaas_configuracao')) {
            return null;
        }
        return DB::table('asaas_configuracao')->where('id', 1)->first();
    }

    private function decifrar(?string $valor): ?string
    {
        if (!$valor) {
            return null;
        }
        try {
            return Crypt::decryptString($valor);
        } catch (DecryptException) {
            // APP_KEY diferente da que cifrou (ex.: banco copiado de outro ambiente).
            return null;
        }
    }

    private function mascarar(string $chave): string
    {
        $prefixo = str_starts_with($chave, '$aact_') ? substr($chave, 0, 11) : substr($chave, 0, 4);
        return $prefixo . '••••••••' . substr($chave, -4);
    }
}
