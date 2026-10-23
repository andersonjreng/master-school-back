<?php

namespace App\Services\Max\Manual;

/**
 * Vídeos tutoriais do YouTube por item do manual (id de ManualSistema). O Max oferece o
 * link junto com o passo a passo. Os vídeos são gerados em master-school/ferramentas/tutoriais;
 * depois de publicar no YouTube, cole aqui o endereço (rótulo => url).
 */
class VideosTutoriais
{
    private const VIDEOS = [
        // 'chamada'      => ['Pelo celular' => 'https://youtu.be/...'],
        // 'avaliacoes'   => ['Pelo computador' => '...', 'Pelo celular' => '...'],
        // 'lancar-notas' => ['Pelo computador' => '...', 'Pelo celular' => '...'],
        // 'medias'       => ['Vídeo' => '...'],
        // 'registro-aula', 'planejamento', 'diario',
        // 'instalar-app', 'push', 'meus-pagamentos', 'boletim', 'mural', 'recados'
    ];

    /** @return array<string, string> rótulo => url (vazio se o item ainda não tem vídeo) */
    public static function doItem(string $id): array
    {
        return self::VIDEOS[$id] ?? [];
    }

    /** @return string[] ids com vídeo cadastrado */
    public static function ids(): array
    {
        return array_keys(self::VIDEOS);
    }
}
