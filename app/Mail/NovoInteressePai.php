<?php

namespace App\Mail;

use App\Models\InteressePai;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso de cada pedido novo (ou reenviado) na landing dos pais. */
class NovoInteressePai extends Mailable
{
    use Queueable, SerializesModels;

    public int $totalEscola;

    public function __construct(public InteressePai $interesse, public bool $novo = true)
    {
        $this->totalEscola = InteressePai::apresentaveis()->where('escola_slug', $interesse->escola_slug)->count();
    }

    public function envelope(): Envelope
    {
        $prefixo = $this->novo ? 'Novo pedido' : 'Pedido atualizado';

        return new Envelope(
            subject: "{$prefixo}: {$this->interesse->escola_nome} ({$this->totalEscola} " . ($this->totalEscola === 1 ? 'família' : 'famílias') . ')',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.novo-interesse-pai',
            with: [
                'i'           => $this->interesse,
                'totalEscola' => $this->totalEscola,
                'waLink'      => 'https://wa.me/55' . $this->interesse->whatsapp,
            ],
        );
    }
}
