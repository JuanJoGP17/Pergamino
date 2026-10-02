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
    public const VERSION = 1;

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
        $data ??= [];

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
