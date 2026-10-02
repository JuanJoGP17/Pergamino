<?php

namespace App\Domain\Schema;

use App\Domain\Formula\Formula;
use App\Models\Template;

/**
 * Panel de avisos del constructor (§6.2, punto 6).
 *
 * Devuelve una lista de incidencias, cada una con nivel:
 *   - error   → impide publicar
 *   - warning → se puede publicar, pero probablemente sea un descuido
 *
 * Se ejecuta en vivo mientras se edita, así que evita consultas por campo:
 * carga el árbol entero una vez y trabaja en memoria.
 */
final class SchemaValidator
{
    private const KEY_PATTERN = '/^[a-z_][a-z0-9_]*$/';

    private const RESERVED = ['self', 'row', 'true', 'false', 'null', 'if', 'and', 'or', 'not'];

    /** @return array<int,array{level:string,message:string,field:?string}> */
    public function validate(Template $template): array
    {
        $template->loadMissing('tabs.sections.fields');

        $issues = [];
        $fields = [];      // key => campo
        $seen = [];        // key => veces vista

        foreach ($template->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach ($section->fields as $field) {
                    $seen[$field->key] = ($seen[$field->key] ?? 0) + 1;
                    $fields[$field->key] = $field;
                }
            }
        }

        if ($fields === []) {
            $issues[] = $this->error('La plantilla no tiene ningún campo todavía.');
        }

        foreach ($seen as $key => $count) {
            if ($count > 1) {
                $issues[] = $this->error(
                    "La clave «{$key}» está repetida en {$count} campos. Las claves deben ser únicas en toda la plantilla.",
                    $key,
                );
            }
        }

        foreach ($fields as $key => $field) {
            $issues = array_merge($issues, $this->validateField($key, $field, $fields));
        }

        foreach ($template->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach (Formula::lint($section->visible_if, array_keys($fields)) as $problem) {
                    $issues[] = $this->error(
                        'En la condición de visibilidad de la sección «'.($section->label ?: $section->key)."»: {$problem}.",
                    );
                }

                if ($section->fields->isEmpty()) {
                    $issues[] = $this->warning(
                        'La sección «'.($section->label ?: $section->key).'» está vacía.',
                    );
                }
                if ($section->columns < 1 || $section->columns > 4) {
                    $issues[] = $this->error(
                        "La sección «{$section->key}» tiene un número de columnas inválido ({$section->columns}). Debe estar entre 1 y 4.",
                    );
                }
            }
        }

        return $issues;
    }

    /** @param array<string,mixed> $all */
    private function validateField(string $key, $field, array $all): array
    {
        $issues = [];

        if (! preg_match(self::KEY_PATTERN, $key)) {
            $issues[] = $this->error(
                "La clave «{$key}» no es válida: usa solo minúsculas, números y guion bajo, y empieza por letra.",
                $key,
            );
        }

        if (in_array($key, self::RESERVED, true)) {
            $issues[] = $this->error("«{$key}» es una palabra reservada del motor de fórmulas.", $key);
        }

        $type = FieldType::tryFrom($field->type);

        if (! $type) {
            $issues[] = $this->error("El campo «{$key}» usa un tipo desconocido: {$field->type}.", $key);

            return $issues;
        }

        if (! $type->isImplemented()) {
            $issues[] = $this->warning(
                "El tipo «{$type->label()}» todavía no está implementado; el campo «{$key}» no se mostrará.",
                $key,
            );
        }

        if (trim((string) $field->label) === '' && $type !== FieldType::Heading) {
            $issues[] = $this->warning("El campo «{$key}» no tiene etiqueta.", $key);
        }

        if ($type->isDerived() && trim((string) $field->formula) === '') {
            $issues[] = $this->error("El campo calculado «{$key}» no tiene fórmula.", $key);
        }

        if (! $type->isDerived() && trim((string) $field->formula) !== '') {
            $issues[] = $this->warning(
                "El campo «{$key}» tiene fórmula pero no es de tipo calculado; la fórmula se ignorará.",
                $key,
            );
        }

        if ($field->col_span < 1 || $field->col_span > 12) {
            $issues[] = $this->error(
                "El campo «{$key}» tiene un ancho inválido ({$field->col_span}). La rejilla es de 12 columnas.",
                $key,
            );
        }

        $issues = array_merge($issues, $this->validateConfig($key, $type, $field->config ?? [], $field));

        // Sintaxis, funciones inexistentes, aridad incorrecta y referencias
        // rotas — todo en una pasada, usando el analizador de verdad.
        $knownKeys = array_keys($all);

        foreach (['formula' => 'la fórmula', 'visible_if' => 'la condición de visibilidad',
            'readonly_if' => 'la condición de solo lectura'] as $slot => $what) {
            foreach (Formula::lint($field->{$slot}, $knownKeys) as $problem) {
                $issues[] = $this->error("En {$what} de «{$key}»: {$problem}.", $key);
            }
        }

        $modFormula = $field->config['mod_formula'] ?? null;

        if ($modFormula !== null && trim((string) $modFormula) !== '') {
            if ($type !== FieldType::Attribute) {
                $issues[] = $this->warning(
                    "El campo «{$key}» define mod_formula pero no es un atributo; se ignorará.",
                    $key,
                );
            }

            foreach (Formula::lint($modFormula, $knownKeys) as $problem) {
                $issues[] = $this->error("En la fórmula del modificador de «{$key}»: {$problem}.", $key);
            }
        }

        // Fórmulas de la configuración de los tipos de rol. `@row` solo existe
        // dentro de las columnas calculadas de un repeater.
        foreach (FieldFormulas::of($type->value, $field->config ?? []) as $slot) {
            $keys = $slot['row'] ? [...$knownKeys, FieldFormulas::ROW] : $knownKeys;

            foreach (Formula::lint($slot['source'], $keys) as $problem) {
                $issues[] = $this->error("En {$slot['label']} de «{$key}»: {$problem}.", $key);
            }
        }

        // La plantilla de tirada es texto con huecos; se valida hueco a hueco.
        foreach ($this->lintRollTemplate($field->roll_expression, $knownKeys) as $problem) {
            $issues[] = $this->error("En la tirada de «{$key}»: {$problem}.", $key);
        }

        return $issues;
    }

    /**
     * Lo que un tipo necesita en su configuración para tener sentido.
     *
     * @return array<int,array>
     */
    private function validateConfig(string $key, FieldType $type, array $config, $field): array
    {
        $empty = fn (string $k) => ! is_array($config[$k] ?? null) || $config[$k] === [];

        $problem = match (true) {
            in_array($type, [FieldType::Select, FieldType::Multiselect], true) && $empty('options') => "La lista «{$key}» no tiene opciones definidas.",
            $type === FieldType::Repeater && $empty('columns') => "La tabla «{$key}» no tiene columnas.",
            $type === FieldType::DerivedList && $empty('items') => "La lista derivada «{$key}» no tiene elementos.",
            in_array($type, [FieldType::Proficiency, FieldType::DerivedList], true) && $empty('levels') => "«{$key}» no tiene niveles de competencia.",
            $type === FieldType::Currency && $empty('denominations') => "La bolsa «{$key}» no tiene monedas.",
            default => null,
        };

        $issues = $problem === null ? [] : [$this->error($problem, $key)];

        if ($type === FieldType::DiceButton && trim((string) $field->roll_expression) === '') {
            $issues[] = $this->warning("El botón de tirada «{$key}» no tiene tirada: escríbela en «Tirada».", $key);
        }

        return $issues;
    }

    /** @return array<int,string> */
    private function lintRollTemplate(?string $template, array $knownKeys): array
    {
        if ($template === null || trim($template) === '') {
            return [];
        }

        $problems = [];

        if (preg_match_all('/\{([^{}]*)\}/', $template, $m)) {
            foreach ($m[1] as $hole) {
                $problems = array_merge($problems, Formula::lint($hole, $knownKeys));
            }
        }

        return $problems;
    }

    private function error(string $message, ?string $field = null): array
    {
        return ['level' => 'error', 'message' => $message, 'field' => $field];
    }

    private function warning(string $message, ?string $field = null): array
    {
        return ['level' => 'warning', 'message' => $message, 'field' => $field];
    }
}
