<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Formulário público "Fale com a gente" do site institucional (sem login). */
class StoreContatoEscolaRequest extends FormRequest
{
    public const CARGOS = ['Mantenedor(a) / Dono(a)', 'Direção', 'Coordenação', 'Secretaria', 'Financeiro', 'Professor(a)', 'Outro'];

    public const FAIXAS_ALUNOS = ['até 150', '151 a 400', '401 a 1000', 'mais de 1000'];

    public const INTERESSES = ['Gestão acadêmica', 'Diário e planejamento', 'Comunicação com as famílias',
                               'Financeiro e cobrança', 'Documentos da secretaria', 'Assistente Max (IA)', 'Avisos no WhatsApp'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $limpa = fn (string $campo) => trim((string) preg_replace('/\s+/u', ' ', (string) $this->input($campo)));

        $this->merge([
            'whatsapp' => preg_replace('/\D/', '', (string) $this->input('whatsapp')),
            'nome'     => $limpa('nome'),
            'escola'   => $limpa('escola'),
            'cidade'   => $limpa('cidade'),
            'email'    => strtolower(trim((string) $this->input('email'))) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'nome'               => ['required', 'string', 'min:2', 'max:120'],
            'cargo'              => ['required', 'string', 'in:' . implode(',', self::CARGOS)],
            'whatsapp'           => ['required', 'digits_between:10,11'],
            'email'              => ['nullable', 'email', 'max:150'],
            'escola'             => ['required', 'string', 'min:3', 'max:150'],
            'cidade'             => ['required', 'string', 'min:2', 'max:80'],
            'alunos'             => ['nullable', 'string', 'in:' . implode(',', self::FAIXAS_ALUNOS)],
            'interesses'         => ['nullable', 'array', 'max:10'],
            'interesses.*'       => ['string', 'in:' . implode(',', self::INTERESSES)],
            'mensagem'           => ['nullable', 'string', 'max:1500'],
            'consentimento_lgpd' => ['accepted'],
            'origem'             => ['nullable', 'string', 'max:80'],
            'website'            => ['nullable', 'max:0'], // armadilha para robôs: tem que vir vazio
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp.digits_between'     => 'Informe um WhatsApp com DDD.',
            'email.email'                 => 'Confira o e-mail.',
            'consentimento_lgpd.accepted' => 'Para enviar, marque a concordância com o uso dos dados.',
            'website.max'                 => 'Não foi possível enviar.',
            'required'                    => 'Preencha todos os campos obrigatórios.',
        ];
    }
}
