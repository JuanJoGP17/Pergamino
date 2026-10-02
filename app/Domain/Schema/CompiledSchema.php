<?php

namespace App\Domain\Schema;

/**
 * Vista de solo lectura del árbol aplanado de una plantilla.
 *
 * Es lo que se guarda en template_versions.compiled_schema y lo único que hace
 * falta leer para renderizar una hoja. Estructura del array:
 *
 *   [
 *     'schema_version' => 1,
 *     'template'  => ['name' => …, 'game_line' => …],
 *     'theme'     => [...],
 *     'tabs'      => [ ['key','label','icon','sections' => [ ... ]] ],
 *     'fields'    => [ 'fuerza' => ['key','type','label','col_span', …] ],
 *     'compute_order' => ['ca', 'iniciativa'],   // orden topológico
 *     'summary_fields' => ['pv', 'ca'],
 *   ]
 *
 * `fields` está indexado por clave para poder resolver referencias de fórmula
 * sin recorrer el árbol; `tabs` conserva el orden y la disposición visual.
 */
final class CompiledSchema
{
    /**
     * Formato del esquema compilado. Súbelo cada vez que cambie la forma de lo
     * que se guarda, y añade a upgrade() cómo leer el formato anterior: las
     * versiones publicadas son inmutables y se quedan con el formato con que
     * se compilaron.
     *
     *   1 → plantillas de tirada como texto con marcadores \0N\0
     *   2 → plantillas de tirada como trozos: ['1d20 + ', 0] (PostgreSQL no
     *       admite \0 en jsonb)
     */
    public const VERSION = 2;

    private function __construct(
        public readonly array $template,
        public readonly array $theme,
        public readonly array $settings,
        public readonly array $tabs,
        public readonly array $fields,
        public readonly array $computeOrder,
        public readonly array $summaryFields,
        public readonly int $schemaVersion,
    ) {}

    public static function fromArray(?array $data): self
    {
        $data = self::upgrade($data ?? []);

        return new self(
            template: $data['template'] ?? [],
            theme: $data['theme'] ?? [],
            settings: $data['settings'] ?? [],
            tabs: $data['tabs'] ?? [],
            fields: $data['fields'] ?? [],
            computeOrder: $data['compute_order'] ?? [],
            summaryFields: $data['summary_fields'] ?? [],
            schemaVersion: $data['schema_version'] ?? self::VERSION,
        );
    }

    /**
     * Lleva un esquema guardado con un formato anterior al actual. Se hace al
     * leer, sin tocar la fila: las versiones publicadas son inmutables.
     */
    private static function upgrade(array $data): array
    {
        if ($data === [] || ($data['schema_version'] ?? 1) >= self::VERSION) {
            return $data;
        }

        // 1 → 2: "1d20 + \0" "0" "\0" → ['1d20 + ', 0]
        foreach ($data['fields'] ?? [] as $key => $field) {
            $roll = $field['roll'] ?? null;

            if (is_array($roll) && isset($roll['template']) && ! isset($roll['parts'])) {
                $parts = [];

                foreach (preg_split('/\x00(\d+)\x00/', $roll['template'], -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $piece) {
                    if ($i % 2 === 1) {
                        $parts[] = (int) $piece;
                    } elseif ($piece !== '') {
                        $parts[] = $piece;
                    }
                }

                $data['fields'][$key]['roll'] = ['parts' => $parts, 'holes' => $roll['holes'] ?? []];
            }
        }

        $data['schema_version'] = self::VERSION;

        return $data;
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'template' => $this->template,
            'theme' => $this->theme,
            'settings' => $this->settings,
            'tabs' => $this->tabs,
            'fields' => $this->fields,
            'compute_order' => $this->computeOrder,
            'summary_fields' => $this->summaryFields,
        ];
    }

    /** AST ya analizado de la fórmula de un campo, o null si no tiene. */
    public function ast(string $key): ?array
    {
        return $this->fields[$key]['ast'] ?? null;
    }

    public function field(string $key): ?array
    {
        return $this->fields[$key] ?? null;
    }

    public function hasField(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function type(string $key): ?FieldType
    {
        return isset($this->fields[$key])
            ? FieldType::tryFrom($this->fields[$key]['type'])
            : null;
    }

    /** @return array<int,array> pestañas con al menos una sección */
    public function visibleTabs(): array
    {
        return array_values(array_filter(
            $this->tabs,
            fn (array $tab) => ! empty($tab['sections']),
        ));
    }

    public function tab(string $key): ?array
    {
        foreach ($this->tabs as $tab) {
            if ($tab['key'] === $key) {
                return $tab;
            }
        }

        return null;
    }

    public function firstTabKey(): ?string
    {
        return $this->visibleTabs()[0]['key'] ?? null;
    }

    /**
     * Valores por defecto de una hoja nueva: todos los campos que guardan valor,
     * con su default o el vacío de su tipo.
     */
    public function defaultData(): array
    {
        $out = [];

        foreach ($this->fields as $key => $field) {
            $type = FieldType::tryFrom($field['type']);

            if (! $type || ! $type->storesValue() || $type->isDerived()) {
                continue;
            }

            $out[$key] = $field['default_value'] ?? $type->emptyValue();
        }

        return $out;
    }
}
