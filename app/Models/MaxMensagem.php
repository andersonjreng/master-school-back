<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaxMensagem extends Model
{
    protected $table = 'max_mensagens';

    protected $fillable = ['max_conversa_id', 'role', 'content', 'tokens_entrada', 'tokens_saida'];

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(MaxConversa::class, 'max_conversa_id');
    }
}
