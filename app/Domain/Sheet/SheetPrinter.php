<?php

namespace App\Domain\Sheet;

use App\Domain\Formula\Value;
use App\Domain\Schema\FieldType;
use App\Models\Media;
use App\Models\Sheet;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Una hoja preparada para imprimir (§6.4): todas las pestañas, una tras otra,
 * con cada campo ya convertido en lo que se ve en papel.
 *
 * Lo usan la página de impresión del navegador y el PDF (dompdf). dompdf no
 * entiende CSS Grid ni variables CSS, así que aquí no se decide nada de estilo:
 * se reparten los campos en FILAS de 12 columnas (como la rejilla de la hoja)
 * para pintarlas con tablas, y cada valor se reduce a texto o a una estructura
 * sencilla (filas de una tabla, casillas de unas marcas).
 *
 * Se respetan las condiciones: lo que visible_if oculta en la hoja tampoco se
 * imprime.
 */
final class SheetPrinter
{
    public function __construct(private Sheet $sheet) {}

    /**
     * @return array<int,array{label:string, sections: array<int,array{label:?string, description:?string, rows: array}>}>
     */
    public function tabs(bool $embedImages = false): array
    {
        $schema = $this->sheet->schema();
        $data = $this->sheet->data ?? [];
        $computed = $this->sheet->computed ?? [];
        $conditions = (new SheetView($schema, $data, $computed))->conditions();
        $rolls = (new SheetView($schema, $data, $computed))->rolls();

        $tabs = [];

        foreach ($schema->visibleTabs() as $tab) {
            $sections = [];

            foreach ($tab['sections'] as $section) {
                if (! ($conditions['sections'][$section['key']] ?? true)) {
                    continue;
                }

                $cells = [];
                foreach ($section['field_keys'] as $key) {
                    $field = $schema->field($key);

                    if (! $field || ! ($conditions['visible'][$key] ?? true)) {
                        continue;
                    }

                    $cells[] = [
                        'key' => $key,
                        'label' => $field['label'],
                        'span' => max(1, min(12, (int) $field['col_span'])),
                        'roll' => $rolls[$key] ?? null,
                        ...$this->value($field, $data[$key] ?? null, $computed[$key] ?? null, $embedImages),
                    ];
                }

                if ($cells !== []) {
                    $sections[] = [
                        'label' => $section['label'],
                        'description' => $section['description'],
                        'rows' => $this->rows($cells),
                    ];
                }
            }

            if ($sections !== []) {
                $tabs[] = ['label' => $tab['label'], 'sections' => $sections];
            }
        }

        return $tabs;
    }

    /**
     * Reparte las celdas en filas que suman como mucho 12 columnas. Un
     * encabezado o una tabla ocupan siempre una fila entera.
     *
     * @return array<int,array<int,array>>
     */
    private function rows(array $cells): array
    {
        $rows = [];
        $row = [];
        $used = 0;

        foreach ($cells as $cell) {
            $span = in_array($cell['kind'], ['heading', 'table', 'list'], true) ? 12 : $cell['span'];
            $cell['span'] = $span;

            if ($used + $span > 12 && $row !== []) {
                $rows[] = $row;
                $row = [];
                $used = 0;
            }

            $row[] = $cell;
            $used += $span;
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Lo que se imprime de un campo. `kind` decide cómo se pinta:
     *   text     → 'text' (y 'sub', una línea menor debajo)
     *   boxes    → 'boxes': ['□', '■', …]
     *   table    → 'columns' y 'rows' (repeater)
     *   list     → 'rows' con nivel, nombre y bonificador (derived_list)
     *   image    → 'src' (data: URI, para dompdf)
     *   heading
     *
     * @return array<string,mixed>
     */
    private function value(array $field, mixed $value, mixed $computed, bool $embedImages): array
    {
        $config = $field['config'] ?? [];
        $type = FieldType::tryFrom($field['type']);
        $text = fn (string $t, ?string $sub = null) => ['kind' => 'text', 'text' => $t, 'sub' => $sub];
        $num = fn ($v) => $v === null || $v === '' ? '—' : Value::toString($v);

        return match ($type) {
            FieldType::Heading => ['kind' => 'heading'],
            FieldType::Text, FieldType::Textarea, FieldType::Color => $text((string) ($value ?? '')),
            FieldType::Number => $text(trim(($config['prefix'] ?? '').' '.$num($value).' '.($config['suffix'] ?? ''))),
            FieldType::Checkbox => $text($value ? '☑' : '☐'),
            FieldType::Select => $text($this->optionLabel($config['options'] ?? [], $value)),
            FieldType::Multiselect => $text(implode(', ', array_map(fn ($v) => $this->optionLabel($config['options'] ?? [], $v), (array) $value))),
            FieldType::Tags => $text(implode(' · ', (array) $value)),
            FieldType::Attribute => $text($num($value), ($config['show_mod'] ?? true) ? SheetCalculator::formatModifier($computed['mod'] ?? null) : null),
            FieldType::Computed => $text(SheetCalculator::formatValue($computed, $config['format'] ?? null)),
            FieldType::Resource => $text(
                $num($value['current'] ?? 0).' / '.$num($computed['max'] ?? $value['max'] ?? 0),
                ($config['show_temp'] ?? true) && ! empty($value['temp']) ? '+'.$value['temp'].' temporales' : null,
            ),
            FieldType::Track => $this->track($config, (array) $value, $computed),
            FieldType::Clock => $text($num($value).' / '.FieldValue::clockSegments($config)),
            FieldType::Counter => $text($num($value).(is_numeric($config['max'] ?? null) ? ' / '.$config['max'] : '')),
            FieldType::Progress => $text($num($value), empty($config['thresholds']) ? null : 'Nivel '.($computed['level'] ?? 0)),
            FieldType::Currency => $this->currency($config, (array) $value, $computed),
            FieldType::Proficiency => $text(
                SheetCalculator::formatModifier($computed['bonus'] ?? null),
                $this->levelLabel($config['levels'] ?? [], $value['level'] ?? null),
            ),
            FieldType::DerivedList => $this->derivedList($config, (array) $value, (array) $computed),
            FieldType::Repeater => $this->repeater($config, (array) $value, (array) $computed),
            FieldType::DiceButton => $text('Tirada'),
            FieldType::Image, FieldType::Portrait => ['kind' => 'image', 'src' => $embedImages ? $this->embed($value) : null, 'media' => $value],
            default => $text(''),
        };
    }

    private function optionLabel(array $options, mixed $value): string
    {
        foreach ($options as $o) {
            if (($o['value'] ?? null) === $value) {
                return (string) ($o['label'] ?? $value);
            }
        }

        return (string) ($value ?? '');
    }

    private function levelLabel(array $levels, mixed $key): string
    {
        foreach ($levels as $l) {
            if (($l['key'] ?? null) === $key) {
                return (string) $l['label'];
            }
        }

        return '';
    }

    private function track(array $config, array $value, mixed $computed): array
    {
        $states = max(1, count($config['states'] ?? []));
        $glyphs = $states === 1 ? ['□', '■'] : ['□', '╱', '✕', '■', '●', '★'];
        $boxes = (int) ($computed['boxes'] ?? FieldValue::trackLength($config));

        $out = [];
        for ($i = 0; $i < $boxes; $i++) {
            $out[] = $glyphs[(int) ($value[$i] ?? 0)] ?? '■';
        }

        return ['kind' => 'boxes', 'boxes' => $out, 'legend' => $states > 1 ? implode(' · ', array_map(
            fn ($label, $i) => ($glyphs[$i + 1] ?? '').' '.$label,
            $config['states'],
            array_keys($config['states']),
        )) : null];
    }

    private function currency(array $config, array $value, mixed $computed): array
    {
        $parts = array_map(fn ($d) => $d['label'].': '.Value::toString($value[$d['key']] ?? 0), $config['denominations'] ?? []);
        $base = collect($config['denominations'] ?? [])->first(fn ($d) => (float) ($d['rate'] ?? 0) === 1.0);

        return ['kind' => 'text', 'text' => implode(' · ', $parts),
            'sub' => $base ? 'Total: '.Value::toString($computed['total'] ?? 0).' '.mb_strtolower($base['label']) : null];
    }

    private function derivedList(array $config, array $value, array $computed): array
    {
        $rows = [];

        foreach ($config['items'] ?? [] as $item) {
            $rows[] = [
                'level' => $this->levelLabel($config['levels'] ?? [], $value[$item['key']]['level'] ?? null),
                'label' => $item['label'],
                'bonus' => SheetCalculator::formatModifier($computed[$item['key']]['bonus'] ?? null),
            ];
        }

        return ['kind' => 'list', 'rows' => $rows];
    }

    private function repeater(array $config, array $value, array $computed): array
    {
        $columns = $config['columns'] ?? [];
        $rows = [];

        foreach (array_values($value) as $i => $row) {
            $cells = [];

            foreach ($columns as $c) {
                $cell = $c['type'] === 'computed' ? ($computed[$i][$c['key']] ?? null) : ($row[$c['key']] ?? null);
                $cells[] = match ($c['type']) {
                    'checkbox' => $cell ? '☑' : '☐',
                    'computed' => SheetCalculator::formatValue($cell),
                    default => Value::toString($cell),
                };
            }

            $rows[] = $cells;
        }

        return ['kind' => 'table', 'columns' => array_column($columns, 'label'), 'rows' => $rows];
    }

    /**
     * La imagen como data: URI en PNG. dompdf no puede pedir URLs (se le
     * desactiva el acceso remoto) y no todas sus versiones leen WebP.
     */
    private function embed(mixed $mediaId): ?string
    {
        $media = is_numeric($mediaId)
            ? Media::whereKey((int) $mediaId)->where('user_id', $this->sheet->owner_id)->first()
            : null;

        if (! $media || ! Storage::disk($media->disk)->exists($media->path)) {
            return null;
        }

        $png = ImageManager::gd()->read(Storage::disk($media->disk)->get($media->path))->scaleDown(600, 600)->toPng();

        return 'data:image/png;base64,'.base64_encode((string) $png);
    }
}
