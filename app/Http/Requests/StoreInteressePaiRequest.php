<?php

namespace App\Http\Requests;

use App\Models\InteressePai;
use Illuminate\Foundation\Http\FormRequest;

/** Formulário público da landing dos pais (sem login). */
class StoreInteressePaiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Escola é texto livre: normaliza espaços e gera o slug que agrupa as variações.
        $escola = trim((string) preg_replace('/\s+/u', ' ', (string) $this->input('escola_nome')));

        $this->merge([
            'whatsapp'    => preg_replace('/\D/', '', (string) $this->input('whatsapp')),
            'nome'        => trim((string) $this->input('nome')),
            'cidade'      => trim((string) $this->input('cidade')),
            'escola_nome' => $escola,
            'escola_slug' => InteressePai::slugDaEscola($escola),
        ]);
    }

    public function rules(): array
    {
        $etapas     = ['Educação Infantil', 'Fundamental I', 'Fundamental II', 'Ensino Médio'];
        $interesses = ['Notas em tempo real', 'Alerta de faltas', 'Comunicados', 'Ocorrências',
                       'Pix e recibos', 'Avisos no WhatsApp', 'Assistente Max'];

        return [
            'nome'               => ['required', 'string', 'min:2', 'max:120'],
            'whatsapp'           => ['required', 'digits_between:10,11'],
            'cidade'             => ['required', 'string', 'min:2', 'max:80'],
            'escola_slug'        => ['required', 'string', 'min:3', 'max:150'],
            'escola_nome'        => ['required', 'string', 'min:3', 'max:150'],
            'etapas'             => ['nullable', 'array', 'max:4'],
            'etapas.*'           => ['string', 'in:' . implode(',', $etapas)],
            'comunicacao_atual'  => ['required', 'string', 'max:60'],
            'interesses'         => ['nullable', 'array', 'max:10'],
            'interesses.*'       => ['string', 'in:' . implode(',', $interesses)],
            'autorizacao'        => ['required', 'in:com_nome,anonimo,nao'],
            'lider'              => ['boolean'],
            'comentario'         => ['nullable', 'string', 'max:1000'],
            'consentimento_lgpd' => ['accepted'],
            'origem'             => ['nullable', 'string', 'max:80'],
            'indicado_por'       => ['nullable', 'string', 'max:80'],
            'website'            => ['nullable', 'max:0'], // armadilha para robôs: tem que vir vazio
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp.digits_between'     => 'Informe um WhatsApp com DDD.',
            'escola_nome.required'        => 'Escreva o nome da escola do seu filho.',
            'escola_nome.min'             => 'Escreva o nome da escola do seu filho.',
            'escola_slug.*'               => 'Escreva o nome da escola do seu filho.',
            'consentimento_lgpd.accepted' => 'Para enviar, marque a concordância com o uso dos dados.',
            'website.max'                 => 'Não foi possível enviar.',
            'required'                    => 'Preencha todos os campos obrigatórios.',
        ];
    }
}
