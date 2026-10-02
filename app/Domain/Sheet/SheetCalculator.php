<?php

namespace App\Domain\Sheet;

use App\Domain\Formula\Evaluator;
use App\Domain\Formula\FormulaRuntimeException;
use App\Domain\Formula\Interpolator;
use App\Domain\Formula\Value;
use App\Domain\Schema\CompiledSchema;
use App\Domain\Schema\FieldType;

/**
 * Calcula los valores derivados de una hoja.
 *
 * Gemelo en el navegador: `resources/js/formula/evaluate.js` → computeAll().
 * Los dos recorren el MISMO orden topológico y evalúan los MISMOS árboles, que
 * vienen ya analizados dentro de compiled_schema.
 *
 * Este lado es el autoritativo: lo que calcula el navegador es solo para que el
 * usuario vea el número sin esperar; al guardar se recalcula aquí y manda esto.
 */
final class SheetCalculator
{
    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed> listo para Sheet::$computed
     */
    public function calculate(CompiledSchema $schema, array $data): array
    {
        [$computed] = $this->calculateWithErrors($schema, $data);

        return $computed;
    }

    /**
     * Igual que calculate(), pero devolviendo además qué fórmulas fallaron.
     *
     * Una fórmula rota NO tumba la hoja: ese campo queda a null y su error se
     * guarda aparte, para poder enseñar «—» con el motivo al lado. En plena
     * partida, una hoja que no abre es mucho peor que un campo vacío.
     *
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    public function calculateWithErrors(CompiledSchema $schema, array $data): array
    {
        $computed = [];
        $errors = [];
        $settings = $schema->settings;

        // 1) Propiedades derivadas incorporadas al tipo de campo. No son
        //    fórmulas: `attribute` siempre expone su modificador. Los que
        //    definen `mod_formula` se calculan en el paso 2, en su turno.
        foreach ($schema->fields as $key => $field) {
            if (($field['type'] ?? null) === FieldType::Attribute->value && empty($field['mod_ast'])) {
                $computed[$key] = [
                    'mod' => $this->attributeModifier(
                        (int) Value::toNumber($data[$key] ?? 0),
                        $field['config'] ?? [],
                        $settings,
                    ),
                ];
            }
        }

        // 2) Campos con fórmula, en el orden topológico ya resuelto al publicar.
        //    Un solo bucle, sin recursión: cuando le toca a un campo, todo
        //    aquello de lo que depende ya está en $computed.
        foreach ($schema->computeOrder as $key) {
            $field = $schema->fields[$key] ?? [];

            if (! empty($field['mod_ast'])) {
                try {
                    $computed[$key] = ['mod' => Evaluator::make($data, $computed, $settings)->evaluate($field['mod_ast'])];
                } catch (FormulaRuntimeException $e) {
                    $computed[$key] = ['mod' => null];
                    $errors[$key] = $e->getMessage();
                }
            }

            // Tipos de rol (resource, track, repeater…): sus propiedades
            // derivadas, con las fórmulas de su configuración.
            if (FieldDerivation::applies($field)) {
                [$computed[$key], $error] = FieldDerivation::derive($key, $field, $data, $computed, $settings);

                if ($error !== null) {
                    $errors[$key] = $error;
                }

                continue;
            }

            if (empty($field['ast'])) {
                continue;
            }

            try {
                $computed[$key] = Evaluator::make($data, $computed, $settings)->evaluate($field['ast']);
            } catch (FormulaRuntimeException $e) {
                $computed[$key] = null;
                $errors[$key] = $e->getMessage();
            }
        }

        return [$computed, $errors];
    }

    /**
     * ¿Se muestra este campo? Sin condición, siempre.
     *
     * Ante un error se muestra: ocultar por un fallo de fórmula dejaría al
     * jugador sin poder tocar un campo sin saber por qué.
     */
    public function isVisible(array $field, array $data, array $computed, array $settings = []): bool
    {
        return $this->condition($field['visible_ast'] ?? null, $data, $computed, $settings, default: true);
    }

    public function isReadonly(array $field, array $data, array $computed, array $settings = []): bool
    {
        return $this->condition($field['readonly_ast'] ?? null, $data, $computed, $settings, default: false);
    }

    /** Las secciones también pueden ocultarse con su propio visible_if. */
    public function isSectionVisible(array $section, array $data, array $computed, array $settings = []): bool
    {
        return $this->condition($section['visible_ast'] ?? null, $data, $computed, $settings, default: true);
    }

    private function condition(?array $ast, array $data, array $computed, array $settings, bool $default): bool
    {
        if ($ast === null) {
            return $default;
        }

        try {
            return Value::toBool(Evaluator::make($data, $computed, $settings)->evaluate($ast));
        } catch (FormulaRuntimeException) {
            return $default;
        }
    }

    /**
     * Expresión de tirada de un campo, con sus huecos ya resueltos:
     * `1d20 + {@destreza.mod}` → `1d20 + 2`.
     */
    public function rollExpression(array $field, array $data, array $computed, array $settings = []): ?string
    {
        return Interpolator::run($field['roll'] ?? null, $data, $computed, $settings);
    }

    /**
     * Modificador de un atributo sin `mod_formula`. El d20 estándar es
     * floor((valor - 10) / 2); el campo, o si no la plantilla, pueden fijar
     * otra base y otro divisor sin escribir una fórmula, porque es el caso con
     * diferencia más común. Para cualquier otra cosa, `mod_formula`.
     */
    public function attributeModifier(int $score, array $config = [], array $settings = []): int
    {
        $base = (int) ($config['mod_base'] ?? $settings['mod_base'] ?? 10);
        $divisor = (int) ($config['mod_divisor'] ?? $settings['mod_divisor'] ?? 2);

        if ($divisor === 0) {
            return 0;
        }

        return (int) floor(($score - $base) / $divisor);
    }

    /** "+3" / "-1" / "+0" — como se pinta un modificador en una hoja. */
    public static function formatModifier(int|float|null $mod): string
    {
        if ($mod === null) {
            return '—';
        }

        return ($mod >= 0 ? '+' : '').Value::toString($mod);
    }

    /**
     * Cómo se pinta el valor de un campo calculado, según `config.format`
     * (§4: int | mod | percent | text).
     *
     * Gemelo: formatValue() en resources/js/formula/sheet.js. No es semántica
     * del lenguaje —solo presentación—, pero si no coincidiera el número
     * «saltaría» al volver la respuesta del servidor.
     */
    public static function formatValue(mixed $value, ?string $format = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => self::formatValue($v, $format), $value));
        }

        return match ($format) {
            'int' => Value::toString(Value::number(self::roundHalfUp(Value::toNumber($value)))),
            'mod' => self::formatModifier(Value::number(Value::toNumber($value))),
            'percent' => Value::toString($value).' %',
            default => Value::toString($value),
        };
    }

    private static function roundHalfUp(float $v): float
    {
        return $v >= 0 ? floor($v + 0.5) : -floor(-$v + 0.5);
    }
}
