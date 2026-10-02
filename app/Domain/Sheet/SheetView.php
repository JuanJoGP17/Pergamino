<?php

namespace App\Domain\Sheet;

use App\Domain\Schema\CompiledSchema;

/**
 * Todo lo que necesita la vista de una hoja, calculado una vez: qué se ve, qué
 * está bloqueado, las tiradas ya resueltas y el esquema mínimo para que el
 * navegador recalcule al teclear.
 *
 * Lo usan el editor de hojas y la vista previa del constructor, así que las dos
 * pintan exactamente igual: la vista previa ES la hoja, no una imitación.
 */
final class SheetView
{
    private SheetCalculator $calc;

    /**
     * @param  array<string,mixed>  $data  valores de la hoja
     * @param  array<string,mixed>  $computed  derivados ya calculados
     */
    public function __construct(
        public readonly CompiledSchema $schema,
        public readonly array $data,
        public readonly array $computed,
    ) {
        $this->calc = new SheetCalculator;
    }

    /**
     * Variables para resources/views/livewire/sheet/_body.blade.php.
     *
     * @return array<string,mixed>
     */
    public function viewData(string $tab, array $formulaErrors = []): array
    {
        $conditions = $this->conditions();

        return [
            'schema' => $this->schema,
            'currentTab' => $this->schema->tab($tab) ?? $this->schema->visibleTabs()[0] ?? null,
            'visible' => $conditions['visible'],
            'readonly' => $conditions['readonly'],
            'sectionVisible' => $conditions['sections'],
            'rolls' => $this->rolls(),
            'formulaErrors' => $formulaErrors,
            'clientSchema' => $this->clientSchema(),
        ];
    }

    /**
     * Estado de cada campo según sus condiciones: visible_if y readonly_if, y
     * el visible_if de cada sección. Es el estado inicial; a partir de ahí el
     * navegador lo recalcula en cada pulsación con los mismos árboles.
     *
     * @return array{visible: array<string,bool>, readonly: array<string,bool>, sections: array<string,bool>}
     */
    public function conditions(): array
    {
        $settings = $this->schema->settings;
        $out = ['visible' => [], 'readonly' => [], 'sections' => []];

        foreach ($this->schema->fields as $key => $field) {
            $out['visible'][$key] = $this->calc->isVisible($field, $this->data, $this->computed, $settings);
            $out['readonly'][$key] = $this->calc->isReadonly($field, $this->data, $this->computed, $settings);
        }

        foreach ($this->schema->tabs as $tab) {
            foreach ($tab['sections'] as $section) {
                $out['sections'][$section['key']] = $this->calc->isSectionVisible($section, $this->data, $this->computed, $settings);
            }
        }

        return $out;
    }

    /**
     * Expresión de tirada de cada campo con sus huecos resueltos.
     *
     * @return array<string,string>
     */
    public function rolls(): array
    {
        $out = [];

        foreach ($this->schema->fields as $key => $field) {
            $expr = $this->calc->rollExpression($field, $this->data, $this->computed, $this->schema->settings);

            if ($expr !== null && trim($expr) !== '') {
                $out[$key] = $expr;
            }
        }

        return $out;
    }

    /**
     * Lo mínimo del esquema que necesita el recalculo en el navegador: árboles,
     * orden y ajustes. No viaja el texto de las fórmulas ni la maquetación, que
     * ya están pintados en el HTML.
     */
    public function clientSchema(): array
    {
        $keep = array_flip(['type', 'config', 'ast', 'mod_ast', 'visible_ast', 'readonly_ast', 'roll']);

        $fields = [];
        foreach ($this->schema->fields as $key => $field) {
            $fields[$key] = array_intersect_key($field, $keep);
        }

        $sections = [];
        foreach ($this->schema->tabs as $tab) {
            foreach ($tab['sections'] as $section) {
                if (! empty($section['visible_ast'])) {
                    $sections[$section['key']] = ['visible_ast' => $section['visible_ast']];
                }
            }
        }

        return [
            'fields' => $fields,
            'sections' => $sections,
            'compute_order' => $this->schema->computeOrder,
            'settings' => $this->schema->settings,
        ];
    }
}
