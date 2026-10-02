<?php

namespace App\Livewire\Sheet;

use App\Domain\Schema\CompiledSchema;
use App\Domain\Sheet\SaveSheet;
use App\Domain\Sheet\SheetCalculator;
use App\Models\Sheet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Editor de una hoja de personaje.
 *
 * El esquema manda: este componente no sabe nada de D&D ni de ningún sistema
 * concreto. Recorre el compiled_schema de la versión a la que está anclada la
 * hoja y pinta lo que encuentre.
 *
 * Guardado: `data` se enlaza con `wire:model.live.blur`, así que cada campo guarda al
 * perder el foco en vez de en cada pulsación. Para la Fase 3 esto pasará a un
 * autoguardado por diferencias con debounce (§11).
 */
#[Layout('components.layouts.app')]
class Editor extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $sheetUuid;

    /** Valores editables, enlazados a la vista. */
    public array $data = [];

    public string $tab = '';

    public ?string $savedAt = null;

    private ?Sheet $sheetCache = null;

    public function mount(Sheet $sheet): void
    {
        $this->authorize('view', $sheet);

        $this->sheetUuid = $sheet->uuid;
        $this->data = $sheet->data ?? [];
        $this->tab = $sheet->schema()->firstTabKey() ?? '';
    }

    public function sheet(): Sheet
    {
        return $this->sheetCache ??= Sheet::with(['version', 'template'])
            ->where('uuid', $this->sheetUuid)
            ->firstOrFail();
    }

    public function schema(): CompiledSchema
    {
        return $this->sheet()->schema();
    }

    public function selectTab(string $key): void
    {
        if ($this->schema()->tab($key)) {
            $this->tab = $key;
        }
    }

    /** Se dispara al perder el foco cualquier campo. */
    public function save(): void
    {
        $sheet = $this->sheet();
        $this->authorize('update', $sheet);

        app(SaveSheet::class)($sheet, $this->data, auth()->user());

        // Releer para que los derivados recién calculados lleguen a la vista,
        // y adoptar los datos tal como quedaron guardados: SaveSheet puede
        // haber descartado claves o revertido un campo de solo lectura.
        $this->sheetCache = $sheet->refresh();
        $this->data = $sheet->data ?? [];
        $this->savedAt = now()->format('H:i:s');
    }

    public function updatedData(): void
    {
        $this->save();
    }

    public function rename(string $name): void
    {
        $sheet = $this->sheet();
        $this->authorize('update', $sheet);

        $name = trim($name);
        $sheet->forceFill(['name' => $name !== '' ? mb_substr($name, 0, 160) : 'Personaje sin nombre'])->save();
    }

    /** Modificador ya calculado de un atributo, para pintarlo junto al valor. */
    public function modifierOf(string $key): int
    {
        return (int) ($this->sheet()->computed[$key]['mod'] ?? 0);
    }

    /**
     * Estado de cada campo según sus condiciones: visible_if y readonly_if.
     *
     * Se calcula en el componente y no en la vista para que Blade no tenga que
     * saber nada del motor de fórmulas. Es el estado inicial; a partir de ahí el
     * navegador lo recalcula en cada pulsación con los mismos árboles.
     *
     * @return array{visible: array<string,bool>, readonly: array<string,bool>, sections: array<string,bool>}
     */
    public function conditions(): array
    {
        $sheet = $this->sheet();
        $schema = $sheet->schema();
        $calc = new SheetCalculator;
        $computed = $sheet->computed ?? [];

        $out = ['visible' => [], 'readonly' => [], 'sections' => []];

        foreach ($schema->fields as $key => $field) {
            $out['visible'][$key] = $calc->isVisible($field, $this->data, $computed, $schema->settings);
            $out['readonly'][$key] = $calc->isReadonly($field, $this->data, $computed, $schema->settings);
        }

        foreach ($schema->tabs as $tab) {
            foreach ($tab['sections'] as $section) {
                $out['sections'][$section['key']] = $calc->isSectionVisible($section, $this->data, $computed, $schema->settings);
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
        $schema = $this->schema();
        $keep = ['type', 'config', 'ast', 'mod_ast', 'visible_ast', 'readonly_ast', 'roll'];

        $fields = [];
        foreach ($schema->fields as $key => $field) {
            $fields[$key] = array_intersect_key($field, array_flip($keep));
        }

        $sections = [];
        foreach ($schema->tabs as $tab) {
            foreach ($tab['sections'] as $section) {
                if (! empty($section['visible_ast'])) {
                    $sections[$section['key']] = ['visible_ast' => $section['visible_ast']];
                }
            }
        }

        return [
            'fields' => $fields,
            'sections' => $sections,
            'compute_order' => $schema->computeOrder,
            'settings' => $schema->settings,
        ];
    }

    /**
     * Expresión de tirada de cada campo con sus huecos resueltos.
     *
     * @return array<string,?string>
     */
    public function rolls(): array
    {
        $sheet = $this->sheet();
        $schema = $sheet->schema();
        $calc = new SheetCalculator;
        $computed = $sheet->computed ?? [];

        $out = [];
        foreach ($schema->fields as $key => $field) {
            $expr = $calc->rollExpression($field, $this->data, $computed, $schema->settings);

            if ($expr !== null && trim($expr) !== '') {
                $out[$key] = $expr;
            }
        }

        return $out;
    }

    public function render()
    {
        $schema = $this->schema();

        $conditions = $this->conditions();

        return view('livewire.sheet.editor', [
            'sheet' => $this->sheet(),
            'schema' => $schema,
            'currentTab' => $schema->tab($this->tab) ?? $schema->visibleTabs()[0] ?? null,
            'visible' => $conditions['visible'],
            'readonly' => $conditions['readonly'],
            'sectionVisible' => $conditions['sections'],
            'rolls' => $this->rolls(),
            'formulaErrors' => $this->sheet()->formulaErrors(),
            'clientSchema' => $this->clientSchema(),
        ]);
    }
}
