<?php

namespace App\Domain\Theme;

use App\Models\Campaign;
use App\Models\Sheet;
use App\Models\Template;

/**
 * El tema con que se pinta una hoja: la cascada plantilla → mesa → hoja
 * (§6.3) ya resuelta, y su CSS.
 *
 * El tema de la plantilla se lee de la plantilla viva, no de la versión a la
 * que está anclada la hoja: es apariencia, no estructura. Cambiar colores no
 * debe obligar a publicar una versión ni a migrar hojas.
 */
final class SheetTheme
{
    public static function forSheet(Sheet $sheet, ?Campaign $campaign = null): array
    {
        $template = $sheet->relationLoaded('template') ? $sheet->template : $sheet->template()->first();

        return Theme::resolve(
            Theme::sanitize($template?->theme),
            Theme::sanitize($campaign?->theme_override),
            Theme::sanitize($sheet->theme_override, sheetLayer: true),
        );
    }

    public static function forTemplate(Template $template): array
    {
        return Theme::resolve(Theme::sanitize($template->theme));
    }

    public static function css(array $theme, string $scope): string
    {
        return ThemeCompiler::css($theme, $scope);
    }
}
