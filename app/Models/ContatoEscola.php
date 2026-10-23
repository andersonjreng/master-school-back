<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Escola que pediu contato/demonstração pelo site institucional
 * (portalmasterschool.com.br). Banco comercial, fora do multi-tenant.
 */
class ContatoEscola extends Model
{
    protected $connection = 'comercial';

    protected $table = 'contato_escolas';

    protected $fillable = [
        'nome', 'cargo', 'whatsapp', 'email',
        'escola', 'cidade', 'alunos', 'interesses', 'mensagem',
        'origem',
        'consentimento_em', 'ip_hash', 'user_agent',
        'status',
    ];

    protected $casts = [
        'interesses'       => 'array',
        'consentimento_em' => 'datetime',
    ];
}
