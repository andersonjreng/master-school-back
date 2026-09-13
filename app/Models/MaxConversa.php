<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaxConversa extends Model
{
    use SoftDeletes;

    protected $table = 'max_conversas';

    protected $fillable = ['usuario_id', 'titulo'];

    public function mensagens(): HasMany
    {
        return $this->hasMany(MaxMensagem::class);
    }
}
