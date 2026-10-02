<?php

namespace App\Domain\Theme;

use App\Domain\Media\MediaUrl;

/**
 * Tema efectivo → variables CSS con ámbito (§6.3).
 *
 *   [data-sheet="uuid"] { --pg-bg: #f4ecd8; --pg-font-heading: "Cinzel", …; … }
 *
 *   @media (prefers-color-scheme: dark) { [data-sheet="uuid"] { --pg-bg: … } }
 *
 * Toda la interfaz de la hoja usa ya var(--pg-*) (ver resources/css/app.css),
 * así que cambiar de tema no toca ni una plantilla Blade: solo estas variables.
 *
 * Lo que sale de aquí va dentro de un <style>. Por eso cada valor viene de una
 * lista blanca (Theme::sanitize) o de este mismo archivo: colores ya
 * validados como #rrggbb, pilas tipográficas del catálogo, números acotados.
 * Ninguna cadena del usuario llega al CSS.
 */
final class ThemeCompiler
{
    private const TEXTURES = [
        'none' => 'none',
        'parchment' => 'radial-gradient(ellipse at 50% 40%, transparent 55%, color-mix(in srgb, var(--pg-ink) 9%, transparent) 100%)',
        'paper' => 'repeating-linear-gradient(0deg, color-mix(in srgb, var(--pg-ink) 4%, transparent) 0 1px, transparent 1px 4px)',
        'grid' => 'linear-gradient(color-mix(in srgb, var(--pg-border) 45%, transparent) 1px, transparent 1px) 0 0 / 24px 24px, linear-gradient(90deg, color-mix(in srgb, var(--pg-border) 45%, transparent) 1px, transparent 1px) 0 0 / 24px 24px',
        'scanlines' => 'repeating-linear-gradient(0deg, color-mix(in srgb, #000 22%, transparent) 0 1px, transparent 1px 3px)',
        'dots' => 'radial-gradient(color-mix(in srgb, var(--pg-ink) 13%, transparent) 1px, transparent 1.6px) 0 0 / 10px 10px',
    ];

    private const SHADOWS = [
        'none' => 'none',
        'soft' => '0 1px 3px rgb(0 0 0 / 0.10), 0 6px 16px rgb(0 0 0 / 0.05)',
        'hard' => '4px 4px 0 var(--pg-ink)',
        'glow' => '0 0 14px color-mix(in srgb, var(--pg-accent) 40%, transparent)',
    ];

    /** [borde de sección, línea interior] */
    private const BORDERS = [
        'solid' => ['1px solid var(--pg-border)', 'none'],
        'double' => ['4px double var(--pg-border)', 'none'],
        'dashed' => ['1px dashed var(--pg-muted)', 'none'],
        'thick' => ['3px solid var(--pg-border)', 'none'],
        'ornate' => ['1px solid var(--pg-border)', '1px solid color-mix(in srgb, var(--pg-accent) 55%, transparent)'],
        'none' => ['0 solid transparent', 'none'],
    ];

    /** [relleno de sección, separación entre campos] */
    private const DENSITIES = [
        'compact' => ['0.65rem', '0.5rem'],
        'comfortable' => ['1rem', '0.75rem'],
        'spacious' => ['1.5rem', '1.15rem'],
    ];

    private const TRANSFORMS = [
        'none' => ['none', 'normal'],
        'uppercase' => ['uppercase', 'normal'],
        'small-caps' => ['none', 'small-caps'],
    ];

    /**
     * @param  array  $theme  tema efectivo (Theme::resolve)
     * @param  string  $scope  uuid de la hoja: el [data-sheet] que lo recibe
     * @param  (callable(int):?string)|null  $mediaUrl  URL de una imagen de fondo
     */
    public static function css(array $theme, string $scope, ?callable $mediaUrl = null): string
    {
        // El ámbito es un uuid o «preview-uuid»; cualquier otra cosa no sale.
        $scope = preg_replace('/[^a-z0-9-]/i', '', $scope);
        $selector = '[data-sheet="'.$scope.'"]';

        $mode = $theme['mode'] ?? 'auto';
        $light = self::palette($theme['colors'] ?? []);
        $dark = self::palette($theme['colors_dark'] ?? []);

        $vars = ($mode === 'dark' ? $dark : $light) + self::layout($theme, $mediaUrl ?? [MediaUrl::class, 'for']);

        $css = $selector.'{'.self::declarations($vars).'}';

        if ($mode === 'auto') {
            $css .= '@media (prefers-color-scheme: dark){'.$selector.'{'.self::declarations($dark).'}}';
        }

        return $css;
    }

    /** @return array<string,string> */
    private static function palette(array $colors): array
    {
        $out = [];

        foreach (Theme::COLOR_KEYS as $key) {
            if ($color = Theme::color($colors[$key] ?? null)) {
                $out['--pg-'.$key] = $color;
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private static function layout(array $theme, callable $mediaUrl): array
    {
        $t = $theme['typography'] ?? [];
        $s = $theme['surface'] ?? [];
        $l = $theme['layout'] ?? [];
        $b = $theme['custom_background'] ?? [];

        [$border, $outline] = self::BORDERS[$s['border_style'] ?? 'solid'] ?? self::BORDERS['solid'];
        [$pad, $gap] = self::DENSITIES[$l['density'] ?? 'comfortable'] ?? self::DENSITIES['comfortable'];
        [$transform, $variant] = self::TRANSFORMS[$t['heading_transform'] ?? 'none'] ?? self::TRANSFORMS['none'];

        $vars = [
            '--pg-font-heading' => Fonts::stack((string) ($t['heading'] ?? 'system_serif')),
            '--pg-font-body' => Fonts::stack((string) ($t['body'] ?? 'system_serif')),
            '--pg-scale' => (string) (float) max(0.85, min(1.3, (float) ($t['scale'] ?? 1))),
            '--pg-heading-transform' => $transform,
            '--pg-heading-variant' => $variant,
            '--pg-texture' => self::TEXTURES[$s['texture'] ?? 'none'] ?? 'none',
            '--pg-radius' => max(0, min(24, (int) ($s['corner_radius'] ?? 8))).'px',
            '--pg-shadow' => self::SHADOWS[$s['shadow'] ?? 'none'] ?? 'none',
            '--pg-section-border' => $border,
            '--pg-section-outline' => $outline,
            '--pg-pad' => $pad,
            '--pg-gap' => $gap,
            '--pg-max-width' => max(640, min(1600, (int) ($l['max_width'] ?? 1100))).'px',
        ];

        $url = ! empty($b['media_id']) ? $mediaUrl((int) $b['media_id']) : null;

        // Una URL firmada solo lleva [A-Za-z0-9:/?=&%._-]; si trajera otra
        // cosa, no se usa.
        if ($url !== null && preg_match('#^https?://[A-Za-z0-9:/?=&%._~-]+$#', $url)) {
            $vars['--pg-bg-image'] = 'url("'.$url.'")';
            $vars['--pg-bg-opacity'] = (string) max(0, min(1, (float) ($b['opacity'] ?? 0.15)));
            $vars['--pg-bg-size'] = ($b['repeat'] ?? 'cover') === 'tile' ? 'auto' : 'cover';
            $vars['--pg-bg-repeat'] = ($b['repeat'] ?? 'cover') === 'tile' ? 'repeat' : 'no-repeat';
        }

        return $vars;
    }

    private static function declarations(array $vars): string
    {
        return implode(';', array_map(fn ($k, $v) => "{$k}:{$v}", array_keys($vars), $vars));
    }
}
