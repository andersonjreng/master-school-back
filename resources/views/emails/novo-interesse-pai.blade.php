<x-mail::message>
# {{ $i->escola_nome }}

**{{ $totalEscola }}** {{ $totalEscola === 1 ? 'família já pediu' : 'famílias já pediram' }} o MasterSchool nesta escola.

<x-mail::panel>
**{{ $i->nome }}** · {{ $i->cidade }}
WhatsApp: {{ $i->whatsapp }}
Autorização: {{ ['com_nome' => 'pode citar o nome', 'anonimo' => 'só na contagem', 'nao' => 'não levar à escola'][$i->autorizacao] ?? $i->autorizacao }}
@if($i->lider)
**Topa conversar com a direção / associação de pais**
@endif
</x-mail::panel>

- **Etapas:** {{ implode(', ', $i->etapas ?? []) ?: 'não informou' }}
- **Comunicação hoje:** {{ $i->comunicacao_atual }}
- **Interesses:** {{ implode(', ', $i->interesses ?? []) ?: 'não marcou' }}
@if($i->origem)
- **Origem:** {{ $i->origem }}
@endif
@if($i->indicado_por)
- **Indicado por:** {{ $i->indicado_por }}
@endif

@if($i->comentario)
> {{ $i->comentario }}
@endif

<x-mail::button :url="$waLink">
Abrir conversa no WhatsApp
</x-mail::button>
</x-mail::message>
