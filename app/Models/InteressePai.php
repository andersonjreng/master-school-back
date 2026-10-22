<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Pedido de um pai/mãe pela landing (pais.portalmasterschool.com.br) para a
 * escola do filho adotar o MasterSchool. Fica no banco comercial, fora do
 * multi-tenant — não pertence a nenhuma escola cliente.
 */
class InteressePai extends Model
{
    protected $connection = 'comercial';

    protected $table = 'interesse_pais';

    protected $fillable = [
        'nome', 'whatsapp', 'cidade',
        'escola_slug', 'escola_nome',
        'etapas', 'comunicacao_atual', 'interesses', 'comentario',
        'autorizacao', 'lider',
        'origem', 'indicado_por',
        'consentimento_em', 'ip_hash', 'user_agent',
        'status',
    ];

    protected $casts = [
        'etapas'           => 'array',
        'interesses'       => 'array',
        'lider'            => 'boolean',
        'consentimento_em' => 'datetime',
    ];

    /** "Colégio São José" e "colegio sao jose " viram a mesma escola. */
    public static function slugDaEscola(?string $nome): string
    {
        return Str::limit(Str::slug((string) $nome), 150, '');
    }

    /** Pedidos que podem ser levados à escola (com nome ou só na contagem). */
    public function scopeApresentaveis(Builder $q): Builder
    {
        return $q->whereIn('autorizacao', ['com_nome', 'anonimo']);
    }

    public function scopeDaEscola(Builder $q, string $nomeOuSlug): Builder
    {
        return $q->where('escola_slug', self::slugDaEscola($nomeOuSlug));
    }
}
