<?php

namespace App\Services\Alunos;

use Illuminate\Support\Facades\Storage;

/**
 * URL pública da foto do aluno.
 *
 * Fotos enviadas por este backend ficam no disco "public" do Laravel
 * (storage/app/public/fotos_alunos, servido em /storage via storage:link).
 * Fotos antigas, enviadas pelo PHP legado, continuam no servidor antigo —
 * para elas a URL aponta para lá.
 */
class FotoAlunoService
{
    public const BASE_LEGADO = 'https://portalmasterschool.com.br/cepelc/api/';

    public static function url(?string $fotoPath): ?string
    {
        if (!$fotoPath) {
            return null;
        }
        if (Storage::disk('public')->exists($fotoPath)) {
            // url() usa o host da requisição — funciona local (127.0.0.1:8010)
            // e em produção (subdomínio de cada escola) sem configuração.
            return url('storage/' . ltrim($fotoPath, '/'));
        }
        return self::BASE_LEGADO . ltrim($fotoPath, '/');
    }
}
