<?php

namespace App\Mail;

use App\Models\ContatoEscola;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso de cada escola que pediu contato pelo site institucional. */
class NovoContatoEscola extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContatoEscola $contato)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Nova escola interessada: {$this->contato->escola} ({$this->contato->cidade})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.novo-contato-escola',
            with: [
                'c'      => $this->contato,
                'waLink' => 'https://wa.me/55' . $this->contato->whatsapp,
            ],
        );
    }
}
