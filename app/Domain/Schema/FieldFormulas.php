<?php

namespace App\Domain\Schema;

/**
 * Fórmulas que viven DENTRO de la configuración de un campo (Fase 4).
 *
 * Los tipos básicos solo tienen las fórmulas de siempre (formula, visible_if,
 * readonly_if, la tirada y el mod_formula del atributo). Los de rol añaden las
 * suyas en `config`:
 *
 *   resource      max_formula            → el máximo de PV sale del nivel y la CON
 *   track         boxes_formula          → Salud de Vampiro = Resistencia + 3
 *   proficiency   base, levels[].bonus   → @destreza.mod + @bono_competencia
 *   derived_list  items[].base, levels[].bonus
 *
 *   repeater      columns[].formula      → @row.peso * @row.cantidad
 *
 * Esta clase es el único sitio que sabe dónde están. La usan el compilador
 * (para analizarlas), las referencias (para el orden de cálculo), el validador
 * y el inspector del constructor (para señalar errores).
 *
 * `@row` solo existe en las columnas calculadas de un repeater: es la fila que
 * se está calculando. En cualquier otro sitio es una referencia rota.
 */
final class FieldFormulas
{
    public const ROW = 'row';

    /**
     * @param  array<string,mixed>  $config
     * @return array<int,array{slot:string,label:string,source:string,row:bool}>
     */
    public static function of(string $type, array $config): array
    {
        $out = [];
        $add = function (string $slot, string $label, mixed $source, bool $row = false) use (&$out) {
            if (is_string($source) && trim($source) !== '') {
                $out[] = ['slot' => $slot, 'label' => $label, 'source' => $source, 'row' => $row];
            }
        };

        $levels = function () use ($config, $add) {
            foreach (self::list($config['levels'] ?? null) as $i => $level) {
                $add("levels.{$i}.bonus", 'el bonificador del nivel «'.($level['label'] ?? $level['key'] ?? $i).'»', $level['bonus'] ?? null);
            }
        };

        switch ($type) {
            case FieldType::Resource->value:
                $add('max_formula', 'la fórmula del máximo', $config['max_formula'] ?? null);
                break;

            case FieldType::Track->value:
                $add('boxes_formula', 'la fórmula del número de casillas', $config['boxes_formula'] ?? null);
                break;

            case FieldType::Proficiency->value:
                $add('base', 'la base', $config['base'] ?? null);
                $levels();
                break;

            case FieldType::DerivedList->value:
                foreach (self::list($config['items'] ?? null) as $i => $item) {
                    $add("items.{$i}.base", 'la base de «'.($item['label'] ?? $item['key'] ?? $i).'»', $item['base'] ?? null);
                }
                $levels();
                break;

            case FieldType::Repeater->value:
                foreach (self::list($config['columns'] ?? null) as $i => $column) {
                    if (($column['type'] ?? null) === 'computed') {
                        $add("columns.{$i}.formula", 'la columna «'.($column['label'] ?? $column['key'] ?? $i).'»', $column['formula'] ?? null, row: true);
                    }
                }
                break;
        }

        return $out;
    }

    /** @return array<int,array> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
