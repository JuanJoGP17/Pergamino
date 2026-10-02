<?php

namespace App\Domain\Builder;

use App\Domain\Schema\FieldType;
use Illuminate\Support\Str;

/**
 * Configuración de cada tipo de campo (§4): la de un campo nuevo, el saneado
 * de lo que llega del inspector y la vuelta a texto para editarla.
 *
 * Las listas (opciones, niveles, columnas…) se editan en el inspector como
 * texto, una por línea y con las partes separadas por «|»:
 *
 *   columnas    nombre | Nombre | texto
 *               peso | Peso | número
 *               tipo | Tipo | lista: Arma, Armadura, Objeto
 *
 *               total | Total | = @row.peso * @row.cantidad
 *   niveles     comp | Competente | @bono_competencia
 *   elementos   sigilo | Sigilo | @destreza.mod
 *   monedas     po | Oro | 100
 *
 * Es más rápido de escribir que un formulario por fila y se puede pegar de una
 * hoja de cálculo. clean() acepta también los arrays ya estructurados, que es
 * como llegan desde los seeders y los bloques prefabricados.
 */
final class FieldConfig
{
    public const SHAPES = ['box', 'dot', 'pip'];

    public const RESETS = ['short', 'long'];

    public const COLUMN_TYPES = ['text', 'number', 'checkbox', 'select', 'computed'];

    /** Lo que se escribe en el inspector → tipo de columna. */
    private const COLUMN_ALIASES = [
        'texto' => 'text', 'text' => 'text',
        'numero' => 'number', 'número' => 'number', 'number' => 'number',
        'casilla' => 'checkbox', 'checkbox' => 'checkbox',
        'lista' => 'select', 'select' => 'select',
    ];

    private const LIMIT = 100;

    public static function defaults(FieldType $type): array
    {
        $levels = [
            ['key' => 'no', 'label' => '—', 'bonus' => '0'],
            ['key' => 'comp', 'label' => 'Competente', 'bonus' => '2'],
            ['key' => 'experto', 'label' => 'Experto', 'bonus' => '4'],
        ];

        return match ($type) {
            FieldType::Attribute => ['min' => 1, 'max' => 30, 'show_mod' => true],
            FieldType::Select, FieldType::Multiselect => ['allow_empty' => true, 'options' => [
                ['value' => 'Opción 1', 'label' => 'Opción 1'],
                ['value' => 'Opción 2', 'label' => 'Opción 2'],
            ]],
            FieldType::Textarea => ['rows' => 4],
            FieldType::Resource => ['show_temp' => true],
            FieldType::Track => ['boxes' => 5, 'shape' => 'box', 'states' => ['Marcada']],
            FieldType::Clock => ['segments' => 4],
            FieldType::Counter => ['min' => 0, 'step' => 1],
            FieldType::Currency => ['denominations' => [
                ['key' => 'pc', 'label' => 'Cobre', 'rate' => 1],
                ['key' => 'pp', 'label' => 'Plata', 'rate' => 10],
                ['key' => 'po', 'label' => 'Oro', 'rate' => 100],
            ]],
            FieldType::Proficiency => ['levels' => $levels],
            FieldType::DerivedList => ['items' => [
                ['key' => 'elemento_1', 'label' => 'Elemento 1', 'base' => '0'],
                ['key' => 'elemento_2', 'label' => 'Elemento 2', 'base' => '0'],
            ], 'levels' => $levels],
            FieldType::Repeater => ['columns' => [
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'cantidad', 'label' => 'Cantidad', 'type' => 'number'],
            ], 'max_rows' => 50],
            FieldType::Image => ['aspect' => 'free'],
            FieldType::Portrait => ['shape' => 'rounded', 'frame' => true],
            default => [],
        };
    }

    public static function defaultSpan(FieldType $type): int
    {
        return match ($type) {
            FieldType::Attribute, FieldType::Color => 2,
            FieldType::Clock, FieldType::Counter, FieldType::Portrait, FieldType::DiceButton => 3,
            FieldType::Number, FieldType::Checkbox, FieldType::Computed,
            FieldType::Resource, FieldType::Proficiency, FieldType::Image => 4,
            FieldType::Text, FieldType::Select, FieldType::Multiselect, FieldType::Tags,
            FieldType::Track, FieldType::Progress, FieldType::Currency, FieldType::DerivedList => 6,
            default => 12,
        };
    }

    /**
     * Solo las claves que el tipo entiende, con su tipo de dato. Lo que no se
     * reconoce se descarta.
     */
    public static function clean(FieldType $type, array $in): array
    {
        $num = fn (string $k) => isset($in[$k]) && is_numeric($in[$k]) ? $in[$k] + 0 : null;
        $int = fn (string $k, int $lo, int $hi, ?int $default = null) => isset($in[$k]) && is_numeric($in[$k])
            ? max($lo, min($hi, (int) $in[$k]))
            : $default;
        $str = fn (string $k, int $max = 255) => isset($in[$k]) && is_scalar($in[$k]) && trim((string) $in[$k]) !== '' ? mb_substr(trim((string) $in[$k]), 0, $max) : null;
        $bool = fn (string $k, bool $default) => array_key_exists($k, $in) ? (bool) $in[$k] : $default;
        $oneOf = fn (string $k, array $allowed, ?string $default = null) => in_array($in[$k] ?? null, $allowed, true) ? $in[$k] : $default;

        $out = match ($type) {
            FieldType::Text => ['maxlength' => $num('maxlength'), 'placeholder' => $str('placeholder')],
            FieldType::Textarea => ['rows' => $int('rows', 1, 30, 4)],
            FieldType::Number => [
                'min' => $num('min'), 'max' => $num('max'), 'step' => $num('step'),
                'prefix' => $str('prefix', 16), 'suffix' => $str('suffix', 16),
            ],
            FieldType::Select => ['allow_empty' => $bool('allow_empty', true), 'options' => self::options($in['options'] ?? [])],
            FieldType::Multiselect => ['options' => self::options($in['options'] ?? []), 'max_selections' => $int('max_selections', 1, 100)],
            FieldType::Tags => ['suggestions' => array_slice(self::lines($in['suggestions'] ?? [], 60), 0, self::LIMIT)],
            FieldType::Attribute => [
                'min' => $num('min'), 'max' => $num('max'),
                'mod_formula' => $str('mod_formula', 2000), 'show_mod' => $bool('show_mod', true),
            ],
            FieldType::Computed => ['format' => $oneOf('format', ['int', 'mod', 'percent', 'text'])],
            FieldType::Resource => [
                'show_temp' => $bool('show_temp', true),
                'allow_overflow' => $bool('allow_overflow', false),
                'bar_color' => is_string($in['bar_color'] ?? null) && preg_match('/^#[0-9a-f]{6}$/i', $in['bar_color']) ? strtolower($in['bar_color']) : null,
                'max_formula' => $str('max_formula', 2000),
                'reset_on' => $oneOf('reset_on', self::RESETS),
            ],
            FieldType::Track => [
                'boxes' => $int('boxes', 1, 30, 5),
                'boxes_formula' => $str('boxes_formula', 2000),
                'shape' => $oneOf('shape', self::SHAPES, 'box'),
                'states' => array_slice(self::lines($in['states'] ?? [], 40), 0, 5) ?: ['Marcada'],
            ],
            FieldType::Clock => ['segments' => $int('segments', 2, 24, 4)],
            FieldType::Counter => [
                'min' => $num('min') ?? 0, 'max' => $num('max'),
                'step' => max(1, (int) ($num('step') ?? 1)),
                'reset_on' => $oneOf('reset_on', self::RESETS),
                'reset_to' => $oneOf('reset_to', ['max', 'min']),
            ],
            FieldType::Progress => ['thresholds' => self::thresholds($in['thresholds'] ?? [])],
            FieldType::Currency => ['denominations' => self::denominations($in['denominations'] ?? [])],
            FieldType::Proficiency => ['base' => $str('base', 2000), 'levels' => self::levels($in['levels'] ?? [])],
            FieldType::DerivedList => ['items' => self::items($in['items'] ?? []), 'levels' => self::levels($in['levels'] ?? [])],
            FieldType::Repeater => [
                'columns' => self::columns($in['columns'] ?? []),
                'min_rows' => $int('min_rows', 0, 200),
                'max_rows' => $int('max_rows', 1, 200, 50),
            ],
            FieldType::Image => ['aspect' => $oneOf('aspect', ['free', 'square', 'portrait', 'landscape'], 'free')],
            FieldType::Portrait => ['shape' => $oneOf('shape', ['circle', 'rounded', 'square'], 'rounded'), 'frame' => $bool('frame', true)],
            default => [],
        };

        return array_filter($out, fn ($v) => $v !== null);
    }

    /**
     * La configuración tal como se edita en el inspector: las listas pasan a
     * texto, una línea por elemento.
     */
    public static function toForm(FieldType $type, array $config): array
    {
        $join = fn (array $rows, callable $line) => implode("\n", array_map($line, $rows));
        $levels = fn () => $join($config['levels'] ?? [], fn ($l) => "{$l['key']} | {$l['label']} | {$l['bonus']}");

        switch ($type) {
            case FieldType::Select:
            case FieldType::Multiselect:
                $config['options'] = $join($config['options'] ?? [], fn ($o) => $o['label'] !== $o['value'] ? "{$o['value']} | {$o['label']}" : $o['value']);
                break;
            case FieldType::Tags:
                $config['suggestions'] = implode("\n", $config['suggestions'] ?? []);
                break;
            case FieldType::Track:
                $config['states'] = implode("\n", $config['states'] ?? []);
                break;
            case FieldType::Progress:
                $config['thresholds'] = implode(', ', $config['thresholds'] ?? []);
                break;
            case FieldType::Currency:
                $config['denominations'] = $join($config['denominations'] ?? [], fn ($d) => "{$d['key']} | {$d['label']} | {$d['rate']}");
                break;
            case FieldType::Proficiency:
                $config['levels'] = $levels();
                break;
            case FieldType::DerivedList:
                $config['items'] = $join($config['items'] ?? [], fn ($i) => rtrim("{$i['key']} | {$i['label']} | ".($i['base'] ?? ''), ' |'));
                $config['levels'] = $levels();
                break;
            case FieldType::Repeater:
                $config['columns'] = $join($config['columns'] ?? [], fn ($c) => "{$c['key']} | {$c['label']} | ".match ($c['type']) {
                    'number' => 'número',
                    'checkbox' => 'casilla',
                    'select' => 'lista: '.implode(', ', $c['options'] ?? []),
                    'computed' => '= '.($c['formula'] ?? ''),
                    default => 'texto',
                });
                break;
        }

        return $config;
    }

    // ============================================================== listas

    /**
     * Partes de cada línea: «a | b | c» → ['a', 'b', 'c']. Un array ya
     * estructurado se devuelve tal cual.
     *
     * @return array<int,array<int,string>|array<string,mixed>>
     */
    private static function rows(mixed $in, int $parts): array
    {
        if (is_array($in)) {
            return array_slice(array_values(array_filter($in, 'is_array')), 0, self::LIMIT);
        }

        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $in)), fn ($l) => $l !== '');

        return array_slice(array_values(array_map(
            fn (string $line) => array_pad(array_map('trim', explode('|', $line, $parts)), $parts, ''),
            $lines,
        )), 0, self::LIMIT);
    }

    /** @return array<int,string> */
    private static function lines(mixed $in, int $max): array
    {
        $list = is_array($in) ? $in : preg_split('/\r\n|\r|\n/', (string) $in);
        $out = [];

        foreach ($list as $line) {
            $line = is_scalar($line) ? mb_substr(trim((string) $line), 0, $max) : '';

            if ($line !== '' && ! in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * Opciones de una lista: array de {value,label} o texto, una por línea,
     * con «valor | etiqueta» opcional.
     *
     * @return array<int,array{value:string,label:string}>
     */
    public static function options(mixed $options): array
    {
        $out = [];
        $seen = [];

        foreach (self::rows($options, 2) as $option) {
            $value = mb_substr(trim((string) ($option['value'] ?? $option[0] ?? '')), 0, 120);
            $label = trim((string) ($option['label'] ?? $option[1] ?? ''));

            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $out[] = ['value' => $value, 'label' => mb_substr($label ?: $value, 0, 120)];
        }

        return $out;
    }

    /** «0, 300, 900» → [0, 300, 900], ordenados y sin repetir. */
    private static function thresholds(mixed $in): array
    {
        $parts = is_array($in) ? $in : preg_split('/[\s,;]+/', (string) $in);
        $numbers = array_values(array_unique(array_map(
            fn ($n) => $n + 0,
            array_filter($parts, fn ($n) => is_numeric($n)),
        )));
        sort($numbers);

        return array_slice($numbers, 0, self::LIMIT);
    }

    /**
     * Claves de una lista interna (columnas, elementos, monedas, niveles):
     * normalizadas como las de campo y únicas dentro de la lista.
     */
    private static function keyFor(string $key, string $label, array &$seen, string $fallback): string
    {
        $base = self::slug($key !== '' ? $key : $label) ?: $fallback;
        $candidate = $base;

        for ($n = 2; isset($seen[$candidate]) || $candidate === 'row'; $n++) {
            $candidate = "{$base}_{$n}";
        }

        $seen[$candidate] = true;

        return $candidate;
    }

    private static function slug(string $text): string
    {
        $key = Str::of(Str::ascii($text))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        if ($key !== '' && ! preg_match('/^[a-z_]/', $key)) {
            $key = 'c_'.$key;
        }

        return mb_substr($key, 0, 48);
    }

    /** @return array<int,array{key:string,label:string,rate:int|float}> */
    private static function denominations(mixed $in): array
    {
        $out = [];
        $seen = [];

        foreach (self::rows($in, 3) as $row) {
            [$key, $label, $rate] = [(string) ($row['key'] ?? $row[0] ?? ''), (string) ($row['label'] ?? $row[1] ?? ''), $row['rate'] ?? $row[2] ?? 1];

            if (trim($key.$label) === '') {
                continue;
            }

            $out[] = [
                'key' => self::keyFor($key, $label, $seen, 'moneda'),
                'label' => mb_substr(trim($label) ?: trim($key), 0, 60),
                'rate' => is_numeric($rate) && $rate > 0 ? $rate + 0 : 1,
            ];
        }

        return $out;
    }

    /** @return array<int,array{key:string,label:string,bonus:string}> */
    private static function levels(mixed $in): array
    {
        $out = [];
        $seen = [];

        foreach (self::rows($in, 3) as $row) {
            [$key, $label, $bonus] = [(string) ($row['key'] ?? $row[0] ?? ''), (string) ($row['label'] ?? $row[1] ?? ''), (string) ($row['bonus'] ?? $row[2] ?? '0')];

            if (trim($key.$label) === '') {
                continue;
            }

            $out[] = [
                'key' => self::keyFor($key, $label, $seen, 'nivel'),
                'label' => mb_substr(trim($label) ?: trim($key), 0, 60),
                'bonus' => mb_substr(trim($bonus) === '' ? '0' : trim($bonus), 0, 2000),
            ];
        }

        return $out;
    }

    /** @return array<int,array{key:string,label:string,base:?string}> */
    private static function items(mixed $in): array
    {
        $out = [];
        $seen = [];

        foreach (self::rows($in, 3) as $row) {
            [$key, $label, $base] = [(string) ($row['key'] ?? $row[0] ?? ''), (string) ($row['label'] ?? $row[1] ?? ''), (string) ($row['base'] ?? $row[2] ?? '')];

            if (trim($key.$label) === '') {
                continue;
            }

            $out[] = array_filter([
                'key' => self::keyFor($key, $label, $seen, 'elemento'),
                'label' => mb_substr(trim($label) ?: trim($key), 0, 60),
                'base' => trim($base) === '' ? null : mb_substr(trim($base), 0, 2000),
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /** @return array<int,array{key:string,label:string,type:string}> */
    private static function columns(mixed $in): array
    {
        $out = [];
        $seen = [];

        foreach (self::rows($in, 3) as $row) {
            $key = (string) ($row['key'] ?? $row[0] ?? '');
            $label = (string) ($row['label'] ?? $row[1] ?? '');

            if (trim($key.$label) === '') {
                continue;
            }

            $column = [
                'key' => self::keyFor($key, $label, $seen, 'columna'),
                'label' => mb_substr(trim($label) ?: trim($key), 0, 60),
            ];

            if (isset($row['type'])) {
                // Ya estructurada (seeders, bloques prefabricados).
                $column['type'] = in_array($row['type'], self::COLUMN_TYPES, true) ? $row['type'] : 'text';
                $options = $row['options'] ?? [];
                $formula = $row['formula'] ?? null;
            } else {
                // «número», «lista: A, B», «= @row.a * 2»…
                $spec = trim((string) ($row[2] ?? ''));
                $options = [];
                $formula = null;

                if (str_starts_with($spec, '=')) {
                    $column['type'] = 'computed';
                    $formula = trim(substr($spec, 1));
                } else {
                    [$word, $rest] = array_pad(array_map('trim', explode(':', $spec, 2)), 2, '');
                    $column['type'] = self::COLUMN_ALIASES[mb_strtolower($word)] ?? 'text';
                    $options = $rest === '' ? [] : explode(',', $rest);
                }
            }

            if ($column['type'] === 'select') {
                $column['options'] = self::lines($options, 60);
            }

            if ($column['type'] === 'computed') {
                $column['formula'] = mb_substr(trim((string) $formula) === '' ? '0' : trim((string) $formula), 0, 2000);
            }

            $out[] = $column;
        }

        return $out;
    }
}
