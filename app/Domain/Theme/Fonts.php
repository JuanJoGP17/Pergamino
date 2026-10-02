<?php

namespace App\Domain\Theme;

/**
 * Tipografías que puede usar un tema (§6.3): un conjunto curado y
 * AUTO-ALOJADO. Los archivos vienen de los paquetes @fontsource de npm y Vite
 * los empaqueta con el resto de la aplicación (resources/css/fonts.css): ni
 * Google Fonts ni ningún CDN en tiempo de ejecución.
 *
 * Un tema solo puede nombrar una de estas claves; el nombre CSS real lo pone
 * este catálogo. Así nunca llega al <style> un texto escrito por el usuario.
 */
final class Fonts
{
    /** clave => [etiqueta, pila CSS] */
    public const CATALOG = [
        'cinzel' => ['Cinzel', '"Cinzel", "Trajan Pro", Georgia, serif'],
        'eb_garamond' => ['EB Garamond', '"EB Garamond", Garamond, Georgia, serif'],
        'im_fell' => ['IM Fell English', '"IM Fell English", Georgia, serif'],
        'inter' => ['Inter', '"Inter", "Segoe UI", system-ui, sans-serif'],
        'jetbrains_mono' => ['JetBrains Mono', '"JetBrains Mono", Consolas, monospace'],
        'bebas_neue' => ['Bebas Neue', '"Bebas Neue", Impact, sans-serif'],
        'special_elite' => ['Special Elite', '"Special Elite", "Courier New", monospace'],
        'system_serif' => ['Serif del sistema', 'Cambria, Georgia, serif'],
        'system_sans' => ['Sans del sistema', 'system-ui, "Segoe UI", sans-serif'],
    ];

    public static function exists(mixed $key): bool
    {
        return is_string($key) && isset(self::CATALOG[$key]);
    }

    public static function stack(string $key): string
    {
        return self::CATALOG[$key][1] ?? self::CATALOG['system_serif'][1];
    }

    /** @return array<string,string> clave => etiqueta, para los selectores */
    public static function options(): array
    {
        return array_map(fn (array $f) => $f[0], self::CATALOG);
    }
}
