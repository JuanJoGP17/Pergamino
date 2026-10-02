<?php

namespace App\Domain\Sheet;

use App\Domain\Schema\FieldType;

/**
 * Forma y saneado del valor de cada tipo de campo (§4 y §10 del plan).
 *
 * «Nunca confiar en el JSON que llega del cliente»: cualquier cosa que llegue
 * del navegador pasa por aquí antes de guardarse, y sale con la forma exacta
 * de su tipo. Un recurso siempre es {current, max, temp} con enteros; unas
 * marcas siempre tienen tantas casillas como dice la plantilla; un repeater
 * nunca pasa de max_rows filas ni guarda columnas que no existen.
 *
 * La misma función da el valor inicial de una hoja nueva: normalize($field, null).
 *
 * No hay gemelo en JS. El navegador solo pinta lo que escribe el usuario; el
 * servidor sanea al guardar y le devuelve el resultado, que manda.
 */
final class FieldValue
{
    public const MAX_TEXT = 10000;

    public const MAX_TRACK_BOXES = 30;

    public const MAX_ROWS = 200;

    public const MAX_TAGS = 50;

    /** Valor saneado de un campo compilado (o null si el tipo no guarda valor). */
    public static function normalize(array $field, mixed $value): mixed
    {
        $type = FieldType::tryFrom($field['type'] ?? '');
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];

        if (! $type || ! $type->storesValue() || $type->isDerived()) {
            return null;
        }

        return match ($type) {
            FieldType::Text, FieldType::Textarea => self::text($value, (int) ($config['maxlength'] ?? self::MAX_TEXT)),
            FieldType::Number, FieldType::Attribute => self::number($value, $type === FieldType::Attribute ? 0 : null),
            FieldType::Checkbox => self::bool($value),
            FieldType::Select => self::choice($value, $config['options'] ?? []),
            FieldType::Multiselect => self::choices($value, $config),
            FieldType::Tags => self::tags($value),
            FieldType::Color => is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : '',
            FieldType::Image, FieldType::Portrait => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
            FieldType::Resource => self::resource($value, $config),
            FieldType::Track => self::track($value, $config),
            FieldType::Clock => self::clamp(self::int($value), 0, self::clockSegments($config)),
            FieldType::Counter => self::counter($value, $config),
            FieldType::Progress => max(0, self::number($value, 0)),
            FieldType::Currency => self::currency($value, $config),
            FieldType::Proficiency => self::proficiency($value, $config),
            FieldType::DerivedList => self::derivedList($value, $config),
            FieldType::Repeater => self::repeater($value, $config),
            default => self::text($value, self::MAX_TEXT),
        };
    }

    // ------------------------------------------------------------- básicos

    private static function text(mixed $value, int $max): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return mb_substr((string) $value, 0, max(1, min($max, self::MAX_TEXT)));
    }

    /**
     * Número tal como lo escribe alguien en un <input>: «16» → 16, «1.5» → 1.5.
     * Vacío o no numérico → $empty. Los atributos nunca quedan vacíos (su
     * modificador se calcula siempre); un número suelto sí puede.
     */
    private static function number(mixed $value, int|float|null $empty): int|float|null
    {
        if (is_bool($value)) {
            return (int) $value;
        }

        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : $empty;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $n = trim($value) + 0;

            return is_finite((float) $n) ? $n : $empty;
        }

        return $empty;
    }

    private static function int(mixed $value, int $empty = 0): int
    {
        $n = self::number($value, $empty);

        return (int) floor((float) $n);
    }

    private static function bool(mixed $value): bool
    {
        return is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOL) : (bool) $value;
    }

    private static function clamp(int|float $n, int|float|null $lo, int|float|null $hi): int|float
    {
        if ($lo !== null && $n < $lo) {
            $n = $lo;
        }

        if ($hi !== null && $n > $hi) {
            $n = $hi;
        }

        return $n;
    }

    /** @return array<int,string> valores de las opciones de una lista */
    private static function optionValues(mixed $options): array
    {
        return array_values(array_map(
            fn ($o) => (string) (is_array($o) ? ($o['value'] ?? '') : $o),
            is_array($options) ? $options : [],
        ));
    }

    private static function choice(mixed $value, mixed $options): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::optionValues($options), true) ? $value : '';
    }

    /** @return array<int,string> */
    private static function choices(mixed $value, array $config): array
    {
        $allowed = self::optionValues($config['options'] ?? []);
        $picked = array_values(array_unique(array_filter(
            array_map(fn ($v) => is_scalar($v) ? (string) $v : null, is_array($value) ? $value : []),
            fn ($v) => $v !== null && in_array($v, $allowed, true),
        )));

        $max = (int) ($config['max_selections'] ?? 0);

        return $max > 0 ? array_slice($picked, 0, $max) : $picked;
    }

    /** @return array<int,string> */
    private static function tags(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $tag) {
            $tag = is_scalar($tag) ? mb_substr(trim((string) $tag), 0, 60) : '';

            if ($tag !== '' && ! in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return array_slice($out, 0, self::MAX_TAGS);
    }

    // ---------------------------------------------------------------- rol

    /**
     * {current, max, temp}. Sin allow_overflow, el actual queda entre 0 y el
     * máximo. Si el máximo sale de una fórmula, el que se guarda es solo un
     * valor de reserva: manda el calculado, y SaveSheet recorta contra él.
     *
     * @return array{current:int, max:int, temp:int}
     */
    public static function resource(mixed $value, array $config, ?int $maxOverride = null): array
    {
        $value = is_array($value) ? $value : [];
        $max = max(0, $maxOverride ?? self::int($value['max'] ?? 0));
        $current = self::int($value['current'] ?? $max);

        // Con el máximo calculado, el guardado todavía no lo conoce: recorta
        // SaveSheet después de calcular, llamando aquí con $maxOverride.
        $maxPending = ! empty($config['max_formula']) && $maxOverride === null;

        if (empty($config['allow_overflow']) && ! $maxPending) {
            $current = self::clamp($current, 0, $max);
        }

        return ['current' => $current, 'max' => $max, 'temp' => max(0, self::int($value['temp'] ?? 0))];
    }

    /** Casillas que se guardan: las fijas, o el tope si salen de una fórmula. */
    public static function trackLength(array $config): int
    {
        if (! empty($config['boxes_formula'])) {
            return self::MAX_TRACK_BOXES;
        }

        return self::clamp((int) ($config['boxes'] ?? 5), 1, self::MAX_TRACK_BOXES);
    }

    /** Cuántos estados además de «vacía»: Vampiro tiene superficial y agravado. */
    public static function trackStates(array $config): int
    {
        $states = $config['states'] ?? [];

        return max(1, is_array($states) ? count($states) : 0);
    }

    /** @return array<int,int> estado de cada casilla: 0 vacía, 1..n */
    private static function track(mixed $value, array $config): array
    {
        $value = is_array($value) ? array_values($value) : [];
        $states = self::trackStates($config);
        $out = [];

        for ($i = 0, $n = self::trackLength($config); $i < $n; $i++) {
            $out[] = self::clamp(self::int($value[$i] ?? 0), 0, $states);
        }

        return $out;
    }

    public static function clockSegments(array $config): int
    {
        return self::clamp((int) ($config['segments'] ?? 4), 2, 24);
    }

    private static function counter(mixed $value, array $config): int|float
    {
        $min = is_numeric($config['min'] ?? null) ? $config['min'] + 0 : 0;
        $max = is_numeric($config['max'] ?? null) ? $config['max'] + 0 : null;

        return self::clamp(self::number($value, $min), $min, $max);
    }

    /** @return array<string,int|float> cantidad de cada moneda */
    private static function currency(mixed $value, array $config): array
    {
        $value = is_array($value) ? $value : [];
        $out = [];

        foreach ($config['denominations'] ?? [] as $d) {
            $key = (string) ($d['key'] ?? '');

            if ($key !== '') {
                $out[$key] = max(0, self::number($value[$key] ?? 0, 0));
            }
        }

        return $out;
    }

    /** @return array{level:string, misc:int|float} */
    private static function proficiency(mixed $value, array $config): array
    {
        $value = is_array($value) ? $value : [];
        $levels = array_map(fn ($l) => (string) ($l['key'] ?? ''), $config['levels'] ?? []);
        $level = is_scalar($value['level'] ?? null) ? (string) $value['level'] : '';

        return [
            'level' => in_array($level, $levels, true) ? $level : ($levels[0] ?? ''),
            'misc' => self::number($value['misc'] ?? 0, 0),
        ];
    }

    /** @return array<string,array{level:string, misc:int|float}> */
    private static function derivedList(mixed $value, array $config): array
    {
        $value = is_array($value) ? $value : [];
        $out = [];

        foreach ($config['items'] ?? [] as $item) {
            $key = (string) ($item['key'] ?? '');

            if ($key !== '') {
                $out[$key] = self::proficiency($value[$key] ?? null, $config);
            }
        }

        return $out;
    }

    /**
     * Filas de una tabla. Solo las columnas declaradas y que no son
     * calculadas (esas las pone SheetCalculator), cada una saneada a su tipo.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function repeater(mixed $value, array $config): array
    {
        $columns = array_filter($config['columns'] ?? [], fn ($c) => ($c['type'] ?? 'text') !== 'computed');
        $rows = is_array($value) ? array_values(array_filter($value, 'is_array')) : [];

        $max = (int) ($config['max_rows'] ?? 0);
        $rows = array_slice($rows, 0, $max > 0 ? min($max, self::MAX_ROWS) : self::MAX_ROWS);

        $min = (int) ($config['min_rows'] ?? 0);
        while (count($rows) < $min) {
            $rows[] = [];
        }

        return array_map(function (array $row) use ($columns) {
            $out = [];

            foreach ($columns as $column) {
                $key = (string) ($column['key'] ?? '');
                $cell = $row[$key] ?? null;

                $out[$key] = match ($column['type'] ?? 'text') {
                    'number' => self::number($cell, null),
                    'checkbox' => self::bool($cell),
                    'select' => in_array($cell, $column['options'] ?? [], true) ? $cell : '',
                    default => self::text($cell, 2000),
                };
            }

            return $out;
        }, $rows);
    }
}
