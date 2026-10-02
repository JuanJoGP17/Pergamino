<?php

namespace App\Domain\Sheet;

use App\Domain\Formula\Evaluator;
use App\Domain\Formula\FormulaRuntimeException;
use App\Domain\Formula\Value;
use App\Domain\Schema\FieldType;

/**
 * Propiedades derivadas de los tipos de rol (Fase 4).
 *
 * Gemelo: resources/js/formula/fields.js → deriveField(). Mismo algoritmo,
 * mismo orden de operaciones: si discreparan, el número «saltaría» al volver
 * la respuesta del servidor. SheetParityTest los compara sobre hojas enteras.
 *
 * Lo que produce cada tipo, en `computed[clave]`:
 *
 *   resource      {max, pct}                @pv.max, @pv.pct   (@pv.current sale del valor)
 *   track         {boxes, marked}           @estres.marked
 *   progress      {level, next, pct}        @xp.level
 *   currency      {total}                   @bolsa.total (en la moneda de rate 1)
 *   proficiency   {bonus}                   @sigilo.bonus      (@sigilo.level, del valor)
 *   derived_list  {item: {bonus}}           @habilidades.sigilo.bonus
 *   repeater      [{columna_calculada: v}]  @inventario[*].total
 *
 * Las fórmulas ya llegan analizadas en `field.derived` (ver SchemaCompiler).
 */
final class FieldDerivation
{
    /** Tipos que calculan algo y por tanto entran en el orden de cálculo. */
    public const TYPES = [
        FieldType::Resource, FieldType::Track, FieldType::Progress, FieldType::Currency,
        FieldType::Proficiency, FieldType::DerivedList, FieldType::Repeater,
    ];

    public static function applies(array $field): bool
    {
        $type = FieldType::tryFrom($field['type'] ?? '');

        if ($type === FieldType::Repeater) {
            return ! empty($field['derived']['columns']);
        }

        return in_array($type, self::TYPES, true);
    }

    /**
     * @return array{0: mixed, 1: ?string} [lo que va a computed[clave], error]
     */
    public static function derive(string $key, array $field, array $data, array $computed, array $settings): array
    {
        $deriver = new self($data, $computed, $settings);
        $value = $data[$key] ?? null;
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $derived = is_array($field['derived'] ?? null) ? $field['derived'] : [];

        $result = match ($field['type']) {
            FieldType::Resource->value => $deriver->resource($value, $derived),
            FieldType::Track->value => $deriver->track($value, $config, $derived),
            FieldType::Progress->value => $deriver->progress($value, $config),
            FieldType::Currency->value => $deriver->currency($value, $config),
            FieldType::Proficiency->value => ['bonus' => $deriver->bonus($value, $derived['base_ast'] ?? null, $derived['levels'] ?? [])],
            FieldType::DerivedList->value => $deriver->derivedList($value, $derived),
            FieldType::Repeater->value => $deriver->repeater($value, $derived),
            default => null,
        };

        return [$result, $deriver->error];
    }

    private ?string $error = null;

    private function __construct(
        private array $data,
        private array $computed,
        private array $settings,
    ) {}

    /** Evalúa una fórmula; si falla, apunta el primer error y devuelve null. */
    private function eval(?array $ast, array $extra = []): mixed
    {
        if ($ast === null) {
            return null;
        }

        try {
            return Evaluator::make($extra + $this->data, $this->computed, $this->settings)->evaluate($ast);
        } catch (FormulaRuntimeException $e) {
            $this->error ??= $e->getMessage();

            return null;
        }
    }

    private function resource(mixed $value, array $derived): array
    {
        $max = isset($derived['max_ast'])
            ? (int) floor(Value::toNumber($this->eval($derived['max_ast'])))
            : (int) floor(Value::toNumber(is_array($value) ? ($value['max'] ?? 0) : 0));

        $current = Value::toNumber(is_array($value) ? ($value['current'] ?? 0) : 0);

        return [
            'max' => $max,
            'pct' => $max > 0 ? Value::number(floor($current * 100 / $max)) : 0,
        ];
    }

    private function track(mixed $value, array $config, array $derived): array
    {
        $cap = FieldValue::trackLength($config);
        $boxes = isset($derived['boxes_ast'])
            ? (int) floor(Value::toNumber($this->eval($derived['boxes_ast'])))
            : $cap;
        $boxes = max(0, min($cap, $boxes));

        $marked = 0;
        $cells = is_array($value) ? array_values($value) : [];
        for ($i = 0; $i < $boxes; $i++) {
            if (Value::toNumber($cells[$i] ?? 0) > 0) {
                $marked++;
            }
        }

        return ['boxes' => $boxes, 'marked' => $marked];
    }

    /**
     * Nivel = umbrales alcanzados. Con los de 5e (0, 300, 900…), 0 PX es
     * nivel 1 y 300 PX nivel 2. `pct` es el avance dentro del tramo actual.
     */
    private function progress(mixed $value, array $config): array
    {
        $xp = Value::toNumber($value);
        $thresholds = array_map(fn ($t) => Value::toNumber($t), is_array($config['thresholds'] ?? null) ? $config['thresholds'] : []);

        $level = 0;
        $previous = 0.0;
        $next = null;

        foreach ($thresholds as $t) {
            if ($xp >= $t) {
                $level++;
                $previous = $t;
            } elseif ($next === null) {
                $next = $t;
            }
        }

        $pct = match (true) {
            $next === null => $level > 0 ? 100 : 0,
            $next <= $previous => 0,
            default => Value::number(floor(($xp - $previous) * 100 / ($next - $previous))),
        };

        return ['level' => $level, 'next' => $next === null ? null : Value::number($next), 'pct' => $pct];
    }

    private function currency(mixed $value, array $config): array
    {
        $value = is_array($value) ? $value : [];
        $total = 0.0;

        foreach ($config['denominations'] ?? [] as $d) {
            $total += Value::toNumber($value[$d['key'] ?? ''] ?? 0) * Value::toNumber($d['rate'] ?? 1);
        }

        return ['total' => Value::number($total)];
    }

    /** base + bonificador del nivel elegido + ajuste manual */
    private function bonus(mixed $value, ?array $baseAst, array $levels): int|float
    {
        $value = is_array($value) ? $value : [];
        $level = $value['level'] ?? null;
        $levelBonus = 0.0;

        foreach ($levels as $l) {
            if (($l['key'] ?? null) === $level) {
                $levelBonus = Value::toNumber($this->eval($l['bonus_ast'] ?? null));
                break;
            }
        }

        return Value::number(Value::toNumber($this->eval($baseAst)) + $levelBonus + Value::toNumber($value['misc'] ?? 0));
    }

    private function derivedList(mixed $value, array $derived): array
    {
        $value = is_array($value) ? $value : [];
        $out = [];

        foreach ($derived['items'] ?? [] as $item) {
            $out[$item['key']] = ['bonus' => $this->bonus($value[$item['key']] ?? null, $item['base_ast'] ?? null, $derived['levels'] ?? [])];
        }

        return $out;
    }

    /**
     * Columnas calculadas, fila a fila. Dentro de la fórmula, `@row` es la
     * fila: sus columnas normales y las calculadas a su izquierda.
     *
     * @return array<int,array<string,mixed>>
     */
    private function repeater(mixed $value, array $derived): array
    {
        $out = [];

        foreach (is_array($value) ? array_values($value) : [] as $row) {
            $row = is_array($row) ? $row : [];
            $cells = [];

            foreach ($derived['columns'] ?? [] as $column) {
                $cells[$column['key']] = $this->eval($column['ast'], ['row' => $row + $cells]);
            }

            $out[] = $cells;
        }

        return $out;
    }
}
