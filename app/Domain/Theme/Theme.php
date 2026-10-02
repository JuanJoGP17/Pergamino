<?php

namespace App\Domain\Theme;

/**
 * Tema de una hoja (§6.3): JSON con propiedades y valores en LISTA BLANCA.
 *
 *   {
 *     "preset": "pergamino",
 *     "mode": "auto" | "light" | "dark",
 *     "colors":      { "bg", "surface", "ink", "accent", "muted", "border", "shade" },
 *     "colors_dark": { … lo mismo para el modo oscuro … },
 *     "typography":  { "heading": clave de Fonts, "body": …, "scale": 0.85–1.3,
 *                      "heading_transform": "none" | "uppercase" | "small-caps" },
 *     "surface":     { "texture", "corner_radius": 0–24, "border_style", "shadow" },
 *     "layout":      { "density": "compact" | "comfortable" | "spacious", "max_width": 640–1600 },
 *     "custom_background": { "media_id", "opacity": 0–1, "repeat": "cover" | "tile" }
 *   }
 *
 * Nunca se acepta CSS crudo: cada valor se valida contra su lista o su rango,
 * y lo que no encaja se descarta. Un color que no sea #rrggbb no existe.
 *
 * Cascada (§6.3): preset → plantilla → mesa → hoja. Cada capa solo dice lo
 * que cambia. Una HOJA solo puede cambiar el acento y el modo claro/oscuro:
 * personaliza sin romper el diseño de la plantilla.
 */
final class Theme
{
    public const COLOR_KEYS = ['bg', 'surface', 'ink', 'accent', 'muted', 'border', 'shade'];

    public const MODES = ['auto', 'light', 'dark'];

    public const TRANSFORMS = ['none', 'uppercase', 'small-caps'];

    public const TEXTURES = ['none', 'parchment', 'paper', 'grid', 'scanlines', 'dots'];

    public const BORDERS = ['solid', 'double', 'dashed', 'thick', 'ornate', 'none'];

    public const SHADOWS = ['none', 'soft', 'hard', 'glow'];

    public const DENSITIES = ['compact', 'comfortable', 'spacious'];

    /**
     * Valida una capa de tema. Devuelve solo lo que es correcto, sin rellenar
     * nada: una capa vacía no cambia nada en la cascada.
     *
     * @param  bool  $sheetLayer  la capa de una hoja: solo acento y modo
     */
    public static function sanitize(mixed $raw, bool $sheetLayer = false): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        if ($sheetLayer) {
            $out['mode'] = self::oneOf($raw['mode'] ?? null, self::MODES);
            $out['colors'] = array_filter(['accent' => self::color($raw['colors']['accent'] ?? null)]);
            $out['colors_dark'] = array_filter(['accent' => self::color($raw['colors_dark']['accent'] ?? null)]);

            return self::prune($out);
        }

        $out['preset'] = isset(Presets::all()[$raw['preset'] ?? '']) ? $raw['preset'] : null;
        $out['mode'] = self::oneOf($raw['mode'] ?? null, self::MODES);

        foreach (['colors', 'colors_dark'] as $palette) {
            $out[$palette] = [];
            foreach (self::COLOR_KEYS as $key) {
                $out[$palette][$key] = self::color($raw[$palette][$key] ?? null);
            }
        }

        $t = is_array($raw['typography'] ?? null) ? $raw['typography'] : [];
        $out['typography'] = [
            'heading' => Fonts::exists($t['heading'] ?? null) ? $t['heading'] : null,
            'body' => Fonts::exists($t['body'] ?? null) ? $t['body'] : null,
            'scale' => self::number($t['scale'] ?? null, 0.85, 1.3, 2),
            'heading_transform' => self::oneOf($t['heading_transform'] ?? null, self::TRANSFORMS),
        ];

        $s = is_array($raw['surface'] ?? null) ? $raw['surface'] : [];
        $out['surface'] = [
            'texture' => self::oneOf($s['texture'] ?? null, self::TEXTURES),
            'corner_radius' => self::number($s['corner_radius'] ?? null, 0, 24, 0),
            'border_style' => self::oneOf($s['border_style'] ?? null, self::BORDERS),
            'shadow' => self::oneOf($s['shadow'] ?? null, self::SHADOWS),
        ];

        $l = is_array($raw['layout'] ?? null) ? $raw['layout'] : [];
        $out['layout'] = [
            'density' => self::oneOf($l['density'] ?? null, self::DENSITIES),
            'max_width' => self::number($l['max_width'] ?? null, 640, 1600, 0),
        ];

        $b = is_array($raw['custom_background'] ?? null) ? $raw['custom_background'] : [];
        $out['custom_background'] = [
            'media_id' => is_numeric($b['media_id'] ?? null) && (int) $b['media_id'] > 0 ? (int) $b['media_id'] : null,
            'opacity' => self::number($b['opacity'] ?? null, 0, 1, 2),
            'repeat' => self::oneOf($b['repeat'] ?? null, ['cover', 'tile']),
        ];

        return self::prune($out);
    }

    /**
     * Tema efectivo: el preset de la capa más específica que lo diga, y encima
     * cada capa en orden. El resultado está completo: todas las claves tienen
     * valor.
     *
     * @param  array<int,array>  $layers  de la más general a la más específica,
     *                                    cada una ya saneada
     */
    public static function resolve(array ...$layers): array
    {
        $preset = Presets::DEFAULT;
        foreach ($layers as $layer) {
            $preset = $layer['preset'] ?? $preset;
        }

        $theme = ['preset' => $preset, 'custom_background' => ['media_id' => null, 'opacity' => 0.15, 'repeat' => 'cover']]
            + array_diff_key(Presets::get($preset), ['label' => true]);

        foreach ($layers as $layer) {
            $theme = array_replace_recursive($theme, array_diff_key($layer, ['preset' => true]));
        }

        return $theme;
    }

    // ------------------------------------------------------------ valores

    public static function color(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : null;
    }

    private static function oneOf(mixed $v, array $allowed): ?string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : null;
    }

    private static function number(mixed $v, float $min, float $max, int $decimals): int|float|null
    {
        if (! is_numeric($v)) {
            return null;
        }

        $n = round(max($min, min($max, (float) $v)), $decimals);

        return $decimals === 0 ? (int) $n : $n;
    }

    /** Quita nulos y grupos vacíos. */
    private static function prune(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $v = self::prune($v);
            }

            if ($v === null || $v === []) {
                unset($a[$k]);
            } else {
                $a[$k] = $v;
            }
        }

        return $a;
    }
}
