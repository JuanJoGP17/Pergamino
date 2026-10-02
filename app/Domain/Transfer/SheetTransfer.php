<?php

namespace App\Domain\Transfer;

use App\Domain\Sheet\CreateSheet;
use App\Domain\Sheet\SaveSheet;
use App\Domain\Theme\Theme;
use App\Models\Sheet;
use App\Models\Template;
use App\Models\User;

/**
 * Exportar e importar hojas en JSON (§6.4).
 *
 *   {
 *     "format": "pergamino.sheet", "format_version": 1,
 *     "template": { uuid, slug, name, version },
 *     "sheet": { name, data, theme_override }
 *   }
 *
 * Solo viajan los VALORES: los derivados se recalculan al importar, y las
 * imágenes (ids de archivos de esta instalación) se quedan fuera.
 *
 * Importar crea una hoja nueva del usuario sobre la versión actual de la
 * plantilla que se elija (por defecto, la misma de la que salió si se puede
 * ver). Los valores pasan por SaveSheet, así que se sanean igual que lo que
 * llega del editor: las claves que la plantilla no conoce se descartan y se
 * avisa de cuántas.
 */
final class SheetTransfer
{
    public const FORMAT = 'pergamino.sheet';

    public const VERSION = 1;

    public function export(Sheet $sheet): array
    {
        $sheet->loadMissing(['template', 'version']);
        $schema = $sheet->schema();

        $data = $sheet->data ?? [];
        foreach ($schema->fields as $key => $field) {
            if (in_array($field['type'], ['image', 'portrait'], true)) {
                unset($data[$key]);
            }
        }

        return [
            'format' => self::FORMAT,
            'format_version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'template' => [
                'uuid' => $sheet->template?->uuid,
                'slug' => $sheet->template?->slug,
                'name' => $sheet->template?->name,
                'version' => $sheet->version?->version,
            ],
            'sheet' => [
                'name' => $sheet->name,
                'data' => $data,
                'theme_override' => Theme::sanitize($sheet->theme_override, sheetLayer: true),
            ],
        ];
    }

    /**
     * La plantilla a la que pertenecía la hoja exportada, si quien importa
     * puede verla y está publicada.
     */
    public function originalTemplate(User $user, mixed $json): ?Template
    {
        $ref = is_array($json['template'] ?? null) ? $json['template'] : [];

        $template = Template::query()
            ->where(fn ($q) => $q->where('uuid', (string) ($ref['uuid'] ?? ''))->orWhere('slug', (string) ($ref['slug'] ?? '')))
            ->whereNotNull('current_version_id')
            ->orderByRaw('CASE WHEN uuid = ? THEN 0 ELSE 1 END', [(string) ($ref['uuid'] ?? '')])
            ->first();

        return $template && $user->can('view', $template) ? $template : null;
    }

    /**
     * @return array{0: Sheet, 1: array<int,string>} la hoja y avisos
     *
     * @throws TransferException
     */
    public function import(User $owner, mixed $json, Template $template): array
    {
        if (! is_array($json) || ($json['format'] ?? null) !== self::FORMAT || ! is_array($json['sheet'] ?? null)) {
            throw new TransferException('El archivo no es una hoja exportada de Pergamino.');
        }

        if ((int) ($json['format_version'] ?? 0) > self::VERSION) {
            throw new TransferException('La hoja se exportó con una versión más nueva de Pergamino.');
        }

        if (! $template->isPublished()) {
            throw new TransferException('Esa plantilla no tiene ninguna versión publicada.');
        }

        $data = is_array($json['sheet']['data'] ?? null) ? $json['sheet']['data'] : [];
        $name = is_scalar($json['sheet']['name'] ?? null) ? trim((string) $json['sheet']['name']) : '';

        $sheet = (new CreateSheet)($template, $owner, mb_substr($name, 0, 160) ?: null);

        $known = $sheet->schema()->defaultData();
        $unknown = array_diff_key($data, $known);

        (new SaveSheet)($sheet, array_intersect_key($data, $known), $owner, revision: false);

        $sheet->forceFill([
            'theme_override' => Theme::sanitize($json['sheet']['theme_override'] ?? null, sheetLayer: true) ?: null,
        ])->save();

        $warnings = $unknown === [] ? [] : [
            count($unknown).' valor(es) no encajan en esta plantilla y no se han importado: '.implode(', ', array_slice(array_keys($unknown), 0, 10)).'.',
        ];

        return [$sheet->fresh(), $warnings];
    }
}
