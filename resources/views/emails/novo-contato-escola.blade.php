<x-mail::message>
# {{ $c->escola }}

{{ $c->cidade }}@if($c->alunos) · {{ $c->alunos }} alunos @endif

<x-mail::panel>
**{{ $c->nome }}** · {{ $c->cargo }}
WhatsApp: {{ $c->whatsapp }}
@if($c->email)
E-mail: {{ $c->email }}
@endif
</x-mail::panel>

- **Interesses:** {{ implode(', ', $c->interesses ?? []) ?: 'não marcou' }}
@if($c->origem)
- **Origem:** {{ $c->origem }}
@endif

@if($c->mensagem)
> {{ $c->mensagem }}
@endif

<x-mail::button :url="$waLink">
Chamar no WhatsApp
</x-mail::button>
</x-mail::message>
