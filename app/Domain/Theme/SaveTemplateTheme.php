<?php

namespace App\Domain\Theme;

use App\Models\Media;
use App\Models\Template;

/**
 * Guarda el tema de una plantilla (editor de apariencia).
 *
 * Lo que llega se sanea por lista blanca (Theme::sanitize). La imagen de fondo
 * tiene que ser del dueño de la plantilla: el id viaja desde el navegador, y
 * sin esta comprobación cualquiera podría poner de fondo una imagen ajena y la
 * hoja la serviría con una URL firmada.
 */
final class SaveTemplateTheme
{
    public function __invoke(Template $template, array $raw): array
    {
        $theme = Theme::sanitize($raw);
        $mediaId = $theme['custom_background']['media_id'] ?? null;

        if ($mediaId && ! Media::whereKey($mediaId)->where('user_id', $template->owner_id)->exists()) {
            unset($theme['custom_background']['media_id']);
        }

        $template->forceFill(['theme' => $theme ?: null])->save();

        return $theme;
    }
}
