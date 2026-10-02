<?php

namespace App\Domain\Theme;

/**
 * Los siete temas incluidos (§6.3). Cada uno define una paleta clara y otra
 * oscura, tipografía, superficie y disposición; un tema de plantilla parte de
 * uno y cambia lo que quiera.
 *
 * Colores: bg (fondo de la hoja), surface (secciones), ink (texto), accent,
 * muted (texto secundario), border y shade (fondos de campo).
 */
final class Presets
{
    public const DEFAULT = 'pergamino';

    /** @return array<string,array> */
    public static function all(): array
    {
        return [
            'pergamino' => [
                'label' => 'Pergamino',
                'mode' => 'auto',
                'colors' => ['bg' => '#f4ecd8', 'surface' => '#fffdf5', 'ink' => '#2b2118', 'accent' => '#8b2e1f', 'muted' => '#7a6a55', 'border' => '#d9c9a8', 'shade' => '#efe6d0'],
                'colors_dark' => ['bg' => '#1b1713', 'surface' => '#262019', 'ink' => '#ece2d0', 'accent' => '#d98b6a', 'muted' => '#9b8b74', 'border' => '#3d342a', 'shade' => '#2f2820'],
                'typography' => ['heading' => 'cinzel', 'body' => 'eb_garamond', 'scale' => 1.0, 'heading_transform' => 'none'],
                'surface' => ['texture' => 'parchment', 'corner_radius' => 8, 'border_style' => 'solid', 'shadow' => 'soft'],
                'layout' => ['density' => 'comfortable', 'max_width' => 1100],
            ],
            'grimorio' => [
                'label' => 'Grimorio oscuro',
                'mode' => 'dark',
                'colors' => ['bg' => '#e9e1d3', 'surface' => '#f6f0e4', 'ink' => '#231a2b', 'accent' => '#6b2d8f', 'muted' => '#6f6378', 'border' => '#c8b9a0', 'shade' => '#e2d8c6'],
                'colors_dark' => ['bg' => '#120e16', 'surface' => '#1c1622', 'ink' => '#e8dcc8', 'accent' => '#c9a227', 'muted' => '#9a8aa8', 'border' => '#3b2f45', 'shade' => '#251d2c'],
                'typography' => ['heading' => 'im_fell', 'body' => 'eb_garamond', 'scale' => 1.05, 'heading_transform' => 'small-caps'],
                'surface' => ['texture' => 'parchment', 'corner_radius' => 4, 'border_style' => 'ornate', 'shadow' => 'glow'],
                'layout' => ['density' => 'comfortable', 'max_width' => 1100],
            ],
            'cyberpunk' => [
                'label' => 'Cyberpunk neón',
                'mode' => 'dark',
                'colors' => ['bg' => '#eef6fb', 'surface' => '#ffffff', 'ink' => '#0b1020', 'accent' => '#d6159c', 'muted' => '#46607a', 'border' => '#7fd4e6', 'shade' => '#e3f1f8'],
                'colors_dark' => ['bg' => '#070a14', 'surface' => '#0e1424', 'ink' => '#e0f7ff', 'accent' => '#ff2bd6', 'muted' => '#7dd3fc', 'border' => '#1e5f74', 'shade' => '#141c30'],
                'typography' => ['heading' => 'bebas_neue', 'body' => 'inter', 'scale' => 1.0, 'heading_transform' => 'uppercase'],
                'surface' => ['texture' => 'grid', 'corner_radius' => 2, 'border_style' => 'solid', 'shadow' => 'glow'],
                'layout' => ['density' => 'compact', 'max_width' => 1200],
            ],
            'minimal' => [
                'label' => 'Minimal papel',
                'mode' => 'auto',
                'colors' => ['bg' => '#f6f6f4', 'surface' => '#ffffff', 'ink' => '#1c1c1c', 'accent' => '#2b59c3', 'muted' => '#6b6b6b', 'border' => '#e2e2de', 'shade' => '#f2f2ef'],
                'colors_dark' => ['bg' => '#141414', 'surface' => '#1c1c1c', 'ink' => '#ececec', 'accent' => '#7aa2ff', 'muted' => '#9a9a9a', 'border' => '#2e2e2e', 'shade' => '#232323'],
                'typography' => ['heading' => 'inter', 'body' => 'inter', 'scale' => 1.0, 'heading_transform' => 'none'],
                'surface' => ['texture' => 'none', 'corner_radius' => 6, 'border_style' => 'solid', 'shadow' => 'none'],
                'layout' => ['density' => 'comfortable', 'max_width' => 1000],
            ],
            'terminal' => [
                'label' => 'Sci-fi terminal',
                'mode' => 'dark',
                'colors' => ['bg' => '#eaf3ea', 'surface' => '#f6fbf6', 'ink' => '#0c2a0c', 'accent' => '#127a12', 'muted' => '#4c6e4c', 'border' => '#a9c9a9', 'shade' => '#e0eee0'],
                'colors_dark' => ['bg' => '#040904', 'surface' => '#081208', 'ink' => '#a6f3a6', 'accent' => '#39ff14', 'muted' => '#5a8f5a', 'border' => '#1f4d1f', 'shade' => '#0d1d0d'],
                'typography' => ['heading' => 'jetbrains_mono', 'body' => 'jetbrains_mono', 'scale' => 0.95, 'heading_transform' => 'uppercase'],
                'surface' => ['texture' => 'scanlines', 'corner_radius' => 0, 'border_style' => 'solid', 'shadow' => 'glow'],
                'layout' => ['density' => 'compact', 'max_width' => 1200],
            ],
            'comic' => [
                'label' => 'Cómic',
                'mode' => 'light',
                'colors' => ['bg' => '#fff6d6', 'surface' => '#ffffff', 'ink' => '#111111', 'accent' => '#e11d48', 'muted' => '#4b4b4b', 'border' => '#111111', 'shade' => '#fde68a'],
                'colors_dark' => ['bg' => '#1a1625', 'surface' => '#242033', 'ink' => '#fafafa', 'accent' => '#facc15', 'muted' => '#c4c4d4', 'border' => '#fafafa', 'shade' => '#2f2a42'],
                'typography' => ['heading' => 'bebas_neue', 'body' => 'inter', 'scale' => 1.05, 'heading_transform' => 'uppercase'],
                'surface' => ['texture' => 'dots', 'corner_radius' => 12, 'border_style' => 'thick', 'shadow' => 'hard'],
                'layout' => ['density' => 'comfortable', 'max_width' => 1100],
            ],
            'maquina' => [
                'label' => 'Máquina de escribir',
                'mode' => 'light',
                'colors' => ['bg' => '#f1ece1', 'surface' => '#faf7f0', 'ink' => '#222222', 'accent' => '#7a1f1f', 'muted' => '#6e6a62', 'border' => '#b9b2a5', 'shade' => '#ebe5d8'],
                'colors_dark' => ['bg' => '#1b1a18', 'surface' => '#242320', 'ink' => '#e7e2d6', 'accent' => '#e07a6a', 'muted' => '#9c978c', 'border' => '#45423c', 'shade' => '#2c2a26'],
                'typography' => ['heading' => 'special_elite', 'body' => 'special_elite', 'scale' => 1.0, 'heading_transform' => 'uppercase'],
                'surface' => ['texture' => 'paper', 'corner_radius' => 0, 'border_style' => 'dashed', 'shadow' => 'none'],
                'layout' => ['density' => 'comfortable', 'max_width' => 1000],
            ],
        ];
    }

    public static function get(?string $key): array
    {
        $all = self::all();

        return $all[$key] ?? $all[self::DEFAULT];
    }

    /** @return array<string,string> clave => etiqueta */
    public static function options(): array
    {
        return array_map(fn (array $p) => $p['label'], self::all());
    }
}
