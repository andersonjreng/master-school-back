<?php

namespace App\Services\Financeiro\Asaas;

use App\Services\Cadastro\CadastroValidacaoService;
use Illuminate\Support\Facades\DB;

/**
 * Responsável pagador ↔ "customer" do Asaas.
 *
 * O Asaas exige só nome e CPF; e-mail, celular e endereço vão junto para
 * aparecerem no boleto/fatura. Os avisos do próprio Asaas ficam desligados
 * (notificationDisabled) — a escola decide como fala com as famílias.
 *
 * A API aceita clientes duplicados, então antes de criar procuramos pelo CPF
 * (ex.: cliente cadastrado à mão no painel, ou criado numa tentativa que
 * deu timeout) e reaproveitamos.
 */
class AsaasClienteService
{
    public function __construct(
        private AsaasClient $api,
        private AsaasConfigService $config,
        private CadastroValidacaoService $validacao,
    ) {
    }

    /**
     * Pagadores que precisam estar no Asaas: o responsável financeiro de cada
     * aluno com parcela em aberto (pendente ou vencida).
     */
    public function pagadores(): array
    {
        $ambiente = $this->config->ambiente();

        $alunosAbertos = DB::table('parcelas')->whereIn('status', ['pendente', 'vencido'])->distinct()->pluck('aluno_id');
        if ($alunosAbertos->isEmpty()) {
            return [];
        }

        // Pagador = responsável principal; sem principal marcado, o primeiro vinculado.
        $vinculos = DB::table('aluno_responsavel as ar')
            ->join('alunos as a', 'a.id', '=', 'ar.aluno_id')
            ->join('usuarios as ua', 'ua.id', '=', 'a.usuario_id')
            ->whereIn('ar.aluno_id', $alunosAbertos)
            ->orderBy('ar.aluno_id')->orderByDesc('ar.principal')->orderBy('ar.responsavel_id')
            ->get(['ar.aluno_id', 'ar.responsavel_id', 'ua.nome_completo as aluno']);

        $alunosPorResp = [];
        $vistos = [];
        foreach ($vinculos as $v) {
            if (isset($vistos[$v->aluno_id])) {
                continue;
            }
            $vistos[$v->aluno_id] = true;
            $alunosPorResp[$v->responsavel_id][] = $v->aluno;
        }
        if (!$alunosPorResp) {
            return [];
        }

        $rows = $this->dadosResponsaveis(array_keys($alunosPorResp), $ambiente);

        return collect($rows)->map(function ($r) use ($alunosPorResp) {
            [$bloqueios, $avisos] = $this->conferir($r);
            return [
                'responsavel_id'  => (int) $r->id,
                'nome'            => $r->nome_completo,
                'cpf'             => $r->cpf,
                'email'           => $r->email,
                'celular'         => $r->telefone,
                'cidade'          => $r->cidade ? trim($r->cidade . ($r->uf ? '/' . $r->uf : '')) : null,
                'alunos'          => $alunosPorResp[$r->id] ?? [],
                'customer_id'     => $r->customer_id,
                'sincronizado_em' => $r->sincronizado_em,
                'situacao'        => $bloqueios ? 'pendente' : ($r->customer_id ? 'sincronizado' : 'pronto'),
                'bloqueios'       => $bloqueios,
                'avisos'          => $avisos,
                // Para abrir o formulário de responsável e corrigir o cadastro ali mesmo.
                'cadastro'        => [
                    'responsavel_id' => (int) $r->id, 'nome_completo' => $r->nome_completo, 'email' => $r->email,
                    'cpf' => $r->cpf, 'telefone' => $r->telefone, 'profissao' => $r->profissao,
                    'notificacao_whatsapp_ativa' => (bool) $r->notificacao_whatsapp_ativa,
                    'cep' => $r->cep, 'endereco' => $r->endereco, 'numero' => $r->numero, 'complemento' => $r->complemento,
                    'bairro' => $r->bairro, 'cidade' => $r->cidade, 'uf' => $r->uf,
                ],
            ];
        })->sortBy([['situacao', 'asc'], ['nome', 'asc']])->values()->all();
    }

    /**
     * Garante que o responsável existe no Asaas (cria ou reaproveita pelo CPF)
     * e atualiza os dados de lá com os nossos. Devolve o customer_id.
     *
     * @throws AsaasException|\DomainException
     */
    public function sincronizar(int $responsavelId): string
    {
        $ambiente = $this->config->ambiente();
        $r = $this->dadosResponsaveis([$responsavelId], $ambiente)[0] ?? null;
        if (!$r) {
            throw new \DomainException('Responsável não encontrado.');
        }
        [$bloqueios] = $this->conferir($r);
        if ($bloqueios) {
            throw new \DomainException('Cadastro incompleto: ' . implode('; ', $bloqueios) . '.');
        }

        $payload = $this->payload($r);
        $customerId = $r->customer_id;

        if ($customerId) {
            try {
                $this->api->put("/customers/$customerId", $payload);
            } catch (AsaasException $e) {
                if ($e->httpStatus !== 404) {
                    throw $e;
                }
                $customerId = null; // removido no painel do Asaas — cria/acha de novo
            }
        }

        if (!$customerId) {
            $existente = $this->api->get('/customers', ['cpfCnpj' => $payload['cpfCnpj'], 'limit' => 1])['data'][0] ?? null;
            if ($existente) {
                $customerId = $existente['id'];
                $this->api->put("/customers/$customerId", $payload);
            } else {
                $customerId = $this->api->post('/customers', $payload)['id'];
            }
        }

        DB::table('asaas_clientes')->updateOrInsert(
            ['responsavel_id' => $responsavelId, 'ambiente' => $ambiente],
            ['customer_id' => $customerId, 'sincronizado_em' => now()]
        );

        return $customerId;
    }

    /**
     * Sincroniza vários, sem parar no primeiro erro. Devolve o resultado de cada um.
     */
    public function sincronizarVarios(array $responsavelIds): array
    {
        $resultado = [];
        foreach (array_unique(array_map('intval', $responsavelIds)) as $id) {
            try {
                $resultado[] = ['responsavel_id' => $id, 'ok' => true, 'customer_id' => $this->sincronizar($id)];
            } catch (AsaasException | \DomainException $e) {
                $resultado[] = ['responsavel_id' => $id, 'ok' => false, 'erro' => $e->getMessage()];
            }
        }
        return $resultado;
    }

    /** customer_id já sincronizado deste responsável no ambiente atual (ou null). */
    public function customerId(int $responsavelId): ?string
    {
        return DB::table('asaas_clientes')
            ->where('responsavel_id', $responsavelId)
            ->where('ambiente', $this->config->ambiente())
            ->value('customer_id');
    }

    private function dadosResponsaveis(array $ids, string $ambiente): array
    {
        return DB::table('responsaveis as r')
            ->join('usuarios as u', 'u.id', '=', 'r.usuario_id')
            ->leftJoin('asaas_clientes as ac', function ($j) use ($ambiente) {
                $j->on('ac.responsavel_id', '=', 'r.id')->where('ac.ambiente', '=', $ambiente);
            })
            ->whereIn('r.id', $ids)
            ->get([
                'r.id', 'u.nome_completo', 'u.email', 'r.cpf', 'r.telefone', 'r.profissao', 'r.notificacao_whatsapp_ativa',
                'r.endereco', 'r.numero', 'r.complemento', 'r.bairro', 'r.cidade', 'r.uf', 'r.cep',
                'ac.customer_id', 'ac.sincronizado_em',
            ])->all();
    }

    /**
     * [bloqueios, avisos]. Bloqueio impede enviar (o Asaas recusaria);
     * aviso só deixa o boleto/fatura mais pobre.
     */
    private function conferir(object $r): array
    {
        $bloqueios = [];
        $avisos = [];
        if (!trim((string) $r->nome_completo)) {
            $bloqueios[] = 'sem nome';
        }
        if (!$this->validacao->cpfValido($r->cpf)) {
            $bloqueios[] = 'CPF inválido';
        }
        if (!$this->validacao->emailValido($r->email)) {
            $avisos[] = 'sem e-mail válido';
        }
        if (!$this->validacao->telefoneValido($r->telefone)) {
            $avisos[] = 'sem celular válido';
        }
        if (strlen($this->validacao->digitos($r->cep)) !== 8 || !trim((string) $r->numero)) {
            $avisos[] = 'endereço incompleto';
        }
        return [$bloqueios, $avisos];
    }

    private function payload(object $r): array
    {
        $cep = $this->validacao->digitos($r->cep);
        $celular = $this->validacao->digitos($r->telefone);

        return array_filter([
            'name'                 => trim($r->nome_completo),
            'cpfCnpj'              => $this->validacao->digitos($r->cpf),
            'email'                => $this->validacao->emailValido($r->email) ? trim($r->email) : null,
            'mobilePhone'          => $this->validacao->telefoneValido($r->telefone) ? $celular : null,
            'postalCode'           => strlen($cep) === 8 ? $cep : null,
            'address'              => $r->endereco ?: null,
            'addressNumber'        => $r->numero ?: null,
            'complement'           => $r->complemento ?: null,
            'province'             => $r->bairro ?: null,
            'externalReference'    => 'responsavel-' . $r->id,
            'notificationDisabled' => true,
        ], fn ($v) => $v !== null);
    }
}
