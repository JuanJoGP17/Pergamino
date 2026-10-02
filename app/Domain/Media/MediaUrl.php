<?php

namespace App\Domain\Media;

use Illuminate\Support\Facades\URL;

/**
 * URL firmada de una imagen guardada en el disco privado.
 *
 * Solo se genera al pintar una hoja que quien mira ya puede ver, así que la
 * firma es la autorización: sin ella, /media/{id} da 403. Caduca, pero a una
 * hora fija (el principio del día siguiente al de mañana) para que la URL no
 * cambie en cada render y el navegador pueda guardar la imagen en caché.
 */
final class MediaUrl
{
    public static function for(?int $mediaId): ?string
    {
        if (! $mediaId) {
            return null;
        }

        return URL::temporarySignedRoute('media.show', now()->addDays(2)->startOfDay(), ['media' => $mediaId]);
    }
}
