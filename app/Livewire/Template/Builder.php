<?php

namespace App\Livewire\Template;

use App\Domain\Builder\BuilderException;
use App\Domain\Builder\FieldConfig;
use App\Domain\Builder\Prefabs;
use App\Domain\Builder\TemplateEditor;
use App\Domain\Formula\Formula;
use App\Domain\Schema\FieldFormulas;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\PublishTemplate;
use App\Domain\Schema\SchemaCompilationException;
use App\Domain\Schema\SchemaValidator;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateSection;
use App\Models\TemplateTab;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Constructor visual de plantillas (§6.1 del plan, Fase 3).
 *
 *   PALETA | LIENZO (o VISTA PREVIA) | INSPECTOR
 *   ─────── panel de validación ───────────────
 *
 * El plan lo repartía en seis componentes Livewire (Palette, Canvas,
 * Inspector, Validator…). Aquí son parciales de UN componente más la vista
 * previa aparte: comparten todo el estado (qué está seleccionado, en qué
 * pestaña estás) y partirlo solo obligaría a sincronizarlo con eventos.
 *
 * La lógica no vive aquí sino en App\Domain\Builder\TemplateEditor. Este
 * componente traduce clics y arrastres a llamadas, y errores a avisos.
 */
#[Layout('components.layouts.app', ['wide' => true])]
class Builder extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $templateUuid;

    /** Pestaña visible en el lienzo. */
    public ?int $tabId = null;

    /** Qué edita el inspector: template | tab | section | field. */
    public string $selection = 'template';

    public ?int $selectedId = null;

    /** Formulario del inspector para lo seleccionado. */
    public array $form = [];

    /** Añadir en bloque (§6.2, punto 3). */
    public string $bulkType = 'number';

    public string $bulkNames = '';

    /** Publicación. */
    public string $publishLabel = '';

    public string $publishChangelog = '';

    /** Aviso para la persona: ['type' => ok|error, 'text' => …]. */
    public ?array $notice = null;

    private ?Template $templateCache = null;

    public function mount(Template $template): void
    {
        $this->authorize('update', $template);

        $this->templateUuid = $template->uuid;
        $this->tabId = $template->tabs()->value('id');
        $this->loadForm();
    }

    // ============================================================ selección

    public function selectTab(int $tabId): void
    {
        $this->tabId = $this->template()->tabs()->whereKey($tabId)->value('id') ?? $this->tabId;
        $this->select('tab', $tabId);
    }

    public function select(string $kind, ?int $id = null): void
    {
        if (! in_array($kind, ['template', 'tab', 'section', 'field'], true)) {
            return;
        }

        $this->selection = $kind;
        $this->selectedId = $kind === 'template' ? null : $id;
        $this->resetErrorBag();
        $this->loadForm();
    }

    /** Desde el panel de validación: ir al campo que tiene el problema. */
    public function selectFieldByKey(string $key): void
    {
        $field = $this->template()->fields()->where('key', $key)->first();

        if ($field) {
            $this->tabId = $field->section->template_tab_id;
            $this->select('field', $field->id);
        }
    }

    // =========================================================== estructura

    public function addTab(): void
    {
        $this->mutate(function (TemplateEditor $editor, Template $template) {
            $tab = $editor->addTab($template);
            $editor->addSection($template, $tab->id, 'Principal');
            $this->tabId = $tab->id;
            $this->select('tab', $tab->id);
        });
    }

    public function addSection(): void
    {
        $this->mutate(function (TemplateEditor $editor, Template $template) {
            $section = $editor->addSection($template, $this->currentTabId());
            $this->select('section', $section->id);
        });
    }

    /** Clic en la paleta: el campo va a la sección seleccionada o a la primera. */
    public function addField(string $type): void
    {
        $this->mutate(function (TemplateEditor $editor, Template $template) use ($type) {
            $sectionId = $this->targetSectionId();

            if ($sectionId === null) {
                $sectionId = $editor->addSection($template, $this->currentTabId())->id;
            }

            $field = $editor->addField($template, $sectionId, $type);
            $this->select('field', $field->id);
        });
    }

    /** Arrastre desde la paleta: tipo, sección y posición exactas. */
    public function addFieldAt(string $type, int $sectionId, int $position): void
    {
        $this->mutate(function (TemplateEditor $editor, Template $template) use ($type, $sectionId, $position) {
            $field = $editor->addField($template, $sectionId, $type, $position);
            $this->select('field', $field->id);
        });
    }

    /** Bloque prefabricado (§6.2, punto 4): una sección entera de un clic. */
    public function insertBlock(string $block): void
    {
        $this->mutate(function (TemplateEditor $editor, Template $template) use ($block) {
            $section = $editor->insertBlock($template, $this->currentTabId(), $block);
            $this->select('section', $section->id);
            $this->notice = ['type' => 'ok', 'text' => 'Bloque «'.Prefabs::get($block)['label'].'» añadido.'];
        });
    }

    public function bulkAdd(): void
    {
        $this->mutate(function (TemplateEditor $editor, Template $template) {
            $sectionId = $this->targetSectionId() ?? throw new BuilderException('Selecciona antes una sección.');
            $fields = $editor->addFieldsInBulk($template, $sectionId, $this->bulkType, $this->bulkNames);
            $this->bulkNames = '';
            $this->notice = ['type' => 'ok', 'text' => count($fields).' campo(s) añadidos.'];
        });
    }

    public function duplicateField(int $fieldId): void
    {
        $this->mutate(function (TemplateEditor $editor, Template $template) use ($fieldId) {
            $copy = $editor->duplicateField($template, $fieldId);
            $this->select('field', $copy->id);
        });
    }

    public function deleteSelected(): void
    {
        $this->delete($this->selection, (int) $this->selectedId);
    }

    /**
     * Borra un campo, sección o pestaña, esté seleccionado o no: el lienzo
     * tiene un botón de borrar en cada tarjeta. Si lo seleccionado desaparece
     * con él (era eso, o vivía dentro), el inspector vuelve a la plantilla.
     */
    public function delete(string $kind, int $id): void
    {
        $this->mutate(function (TemplateEditor $editor, Template $template) use ($kind, $id) {
            match ($kind) {
                'field' => $editor->deleteField($template, $id),
                'section' => $editor->deleteSection($template, $id),
                'tab' => $editor->deleteTab($template, $id),
                default => throw new BuilderException('Selecciona antes qué borrar.'),
            };

            if (! $template->tabs()->whereKey($this->tabId)->exists()) {
                $this->tabId = $template->tabs()->value('id');
            }

            if (! $this->selectionExists($template)) {
                $this->select('template');
            }
        });
    }

    // ============================================================ arrastre

    public function moveTab(int $tabId, int $position): void
    {
        $this->mutate(fn (TemplateEditor $editor, Template $template) => $editor->moveTab($template, $tabId, $position));
    }

    public function moveSection(int $sectionId, int $tabId, int $position): void
    {
        $this->mutate(fn (TemplateEditor $editor, Template $template) => $editor->moveSection($template, $sectionId, $tabId, $position));
    }

    public function moveField(int $fieldId, int $sectionId, int $position): void
    {
        $this->mutate(fn (TemplateEditor $editor, Template $template) => $editor->moveField($template, $fieldId, $sectionId, $position));
    }

    // ============================================================ inspector

    /**
     * Cada propiedad del inspector se guarda al salir del input. `$key` llega
     * como «label» o «config.min»: se guarda la propiedad de primer nivel.
     */
    public function updatedForm(mixed $value, string $key): void
    {
        $prop = explode('.', $key)[0];

        $this->mutate(function (TemplateEditor $editor, Template $template) use ($prop) {
            $id = (int) $this->selectedId;
            $value = $this->form[$prop] ?? null;

            match ($this->selection) {
                'template' => $editor->updateSettings($template, [$prop => $value]),
                'tab' => $editor->updateTab($template, $id, [$prop => $value]),
                'section' => $prop === 'tab_id'
                    ? $editor->moveSection($template, $id, (int) $value, PHP_INT_MAX >> 48)
                    : $editor->updateSection($template, $id, [$prop => $value]),
                'field' => match ($prop) {
                    'section_id' => $editor->moveField($template, $id, (int) $value, PHP_INT_MAX >> 48),
                    'config' => $editor->updateField($template, $id, ['config' => $this->configFromForm()]),
                    default => $editor->updateField($template, $id, [$prop => $value]),
                },
                default => null,
            };

            // Si cambió de sección o de pestaña, el lienzo debe seguirlo.
            if (in_array($prop, ['section_id', 'tab_id'], true)) {
                $this->tabId = $this->selection === 'field'
                    ? TemplateSection::find((int) $value)?->template_tab_id
                    : (int) $value;
            }
        }, errorKey: 'form.'.$prop);

        $this->loadForm();
    }

    // ========================================================== publicación

    public function publish(): void
    {
        $template = $this->template();
        $this->authorize('publish', $template);

        try {
            $version = app(PublishTemplate::class)(
                $template,
                auth()->user(),
                label: trim($this->publishLabel) ?: null,
                changelog: trim($this->publishChangelog) ?: null,
            );
        } catch (SchemaCompilationException $e) {
            $this->notice = ['type' => 'error', 'text' => 'No se puede publicar: '.$e->getMessage()];

            return;
        }

        $this->publishLabel = '';
        $this->publishChangelog = '';
        $this->templateCache = null;
        $this->notice = ['type' => 'ok', 'text' => "Publicada la versión {$version->version}. Las hojas nuevas ya la usan; las existentes siguen en la suya."];
    }

    // ============================================================== render

    public function render()
    {
        $template = $this->template()->load(['tabs.sections.fields', 'currentVersion']);
        $issues = (new SchemaValidator)->validate($template);
        $keys = $template->tabs->flatMap->sections->flatMap->fields->pluck('key')->all();

        return view('livewire.template.builder', [
            'template' => $template,
            'currentTab' => $template->tabs->firstWhere('id', $this->tabId) ?? $template->tabs->first(),
            'issues' => $issues,
            'errorCount' => count(array_filter($issues, fn ($i) => $i['level'] === 'error')),
            'unpublished' => TemplateEditor::hasUnpublishedChanges($template),
            'palette' => collect(FieldType::implemented())->groupBy(fn (FieldType $t) => $t->group()),
            'prefabs' => Prefabs::all(),
            'types' => FieldType::implemented(),
            'lint' => $this->selection === 'field' ? $this->lintForm($keys) : [],
            'knownKeys' => $keys,
        ]);
    }

    // ============================================================ internos

    public function template(): Template
    {
        return $this->templateCache ??= Template::where('uuid', $this->templateUuid)->firstOrFail();
    }

    /**
     * Ejecuta una operación sobre el borrador: permisos, errores legibles y
     * aviso a la vista previa para que se repinte.
     */
    private function mutate(callable $operation, ?string $errorKey = null): void
    {
        $template = $this->template();
        $this->authorize('update', $template);
        $this->notice = null;

        try {
            $operation(app(TemplateEditor::class), $template);
        } catch (BuilderException $e) {
            if ($errorKey) {
                $this->addError($errorKey, $e->getMessage());
            } else {
                $this->notice = ['type' => 'error', 'text' => $e->getMessage()];
            }

            return;
        }

        $this->templateCache = null;
        $this->dispatch('template-changed')->to(Preview::class);
    }

    private function selectionExists(Template $template): bool
    {
        return match ($this->selection) {
            'field' => $template->fields()->whereKey($this->selectedId)->exists(),
            'section' => TemplateSection::whereKey($this->selectedId)
                ->whereIn('template_tab_id', $template->tabs()->select('id'))->exists(),
            'tab' => $template->tabs()->whereKey($this->selectedId)->exists(),
            default => true,
        };
    }

    private function currentTabId(): int
    {
        return $this->tabId ?? $this->template()->tabs()->value('id')
            ?? throw new BuilderException('La plantilla no tiene pestañas.');
    }

    /** Sección donde caen los campos nuevos: la seleccionada, la del campo seleccionado o la primera de la pestaña. */
    private function targetSectionId(): ?int
    {
        return match ($this->selection) {
            'section' => $this->selectedId,
            'field' => TemplateField::where('template_id', $this->template()->id)->whereKey($this->selectedId)->value('template_section_id'),
            default => TemplateSection::where('template_tab_id', $this->currentTabId())->orderBy('position')->value('id'),
        };
    }

    /** Rellena el inspector con lo que hay guardado ahora mismo. */
    private function loadForm(): void
    {
        $template = $this->template()->fresh();
        $this->templateCache = $template;

        $this->form = match ($this->selection) {
            'field' => $this->fieldForm($template),
            'section' => $this->sectionForm($template),
            'tab' => $this->tabForm($template),
            default => $this->templateForm($template),
        };

        // Si lo seleccionado ya no existe (se borró), volver a la plantilla.
        if ($this->form === [] && $this->selection !== 'template') {
            $this->selection = 'template';
            $this->selectedId = null;
            $this->form = $this->templateForm($template);
        }
    }

    private function templateForm(Template $t): array
    {
        $s = $t->settings ?? [];

        return [
            'name' => $t->name,
            'tagline' => $t->tagline,
            'game_line' => $t->game_line,
            'visibility' => $t->visibility,
            'mod_base' => $s['mod_base'] ?? 10,
            'mod_divisor' => $s['mod_divisor'] ?? 2,
            'prof_base' => $s['prof_base'] ?? 2,
            'prof_step' => $s['prof_step'] ?? 4,
            'lookups' => empty($s['lookups']) ? '' : json_encode($s['lookups'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function tabForm(Template $t): array
    {
        $tab = TemplateTab::where('template_id', $t->id)->find($this->selectedId);

        return $tab ? ['key' => $tab->key, 'label' => $tab->label] : [];
    }

    private function sectionForm(Template $t): array
    {
        $section = TemplateSection::whereIn('template_tab_id', $t->tabs()->pluck('id'))->find($this->selectedId);

        return $section ? [
            'key' => $section->key,
            'label' => $section->label,
            'description' => $section->description,
            'columns' => $section->columns,
            'visible_if' => $section->visible_if,
            'tab_id' => $section->template_tab_id,
        ] : [];
    }

    private function fieldForm(Template $t): array
    {
        $field = TemplateField::where('template_id', $t->id)->find($this->selectedId);

        if (! $field) {
            return [];
        }

        // Las listas (opciones, columnas, niveles…) se editan como texto.
        $config = FieldConfig::toForm(FieldType::from($field->type), $field->config ?? []);

        return [
            'key' => $field->key,
            'label' => $field->label,
            'type' => $field->type,
            'help_text' => $field->help_text,
            'col_span' => $field->col_span,
            'section_id' => $field->template_section_id,
            'config' => $config,
            'default_value' => $field->default_value['value'] ?? null,
            'formula' => $field->formula,
            'roll_expression' => $field->roll_expression,
            'visible_if' => $field->visible_if,
            'readonly_if' => $field->readonly_if,
            'is_required' => $field->is_required,
            'is_summary' => $field->is_summary,
        ];
    }

    /** La configuración del formulario, tal como la entiende TemplateEditor. */
    private function configFromForm(): array
    {
        return is_array($this->form['config'] ?? null) ? $this->form['config'] : [];
    }

    /**
     * Problemas de cada fórmula del campo seleccionado, para pintarlos junto al
     * input. Es el mismo análisis que usa el panel de validación.
     *
     * @return array<string,array<int,string>>
     */
    private function lintForm(array $knownKeys): array
    {
        $out = [];

        foreach (['formula', 'visible_if', 'readonly_if'] as $slot) {
            $out[$slot] = Formula::lint($this->form[$slot] ?? null, $knownKeys);
        }

        $out['mod_formula'] = Formula::lint($this->form['config']['mod_formula'] ?? null, $knownKeys);

        // Fórmulas de la configuración de los tipos de rol. El inspector las
        // tiene como texto: se pasan antes por FieldConfig para leerlas igual
        // que las leerá el compilador.
        $type = FieldType::tryFrom((string) ($this->form['type'] ?? ''));
        if ($type) {
            foreach (FieldFormulas::of($type->value, FieldConfig::clean($type, $this->configFromForm())) as $slot) {
                $keys = $slot['row'] ? [...$knownKeys, FieldFormulas::ROW] : $knownKeys;

                foreach (Formula::lint($slot['source'], $keys) as $problem) {
                    $out['config'][] = "En {$slot['label']}: {$problem}";
                }
            }
        }

        $out['roll_expression'] = [];
        if (preg_match_all('/\{([^{}]*)\}/', (string) ($this->form['roll_expression'] ?? ''), $m)) {
            foreach ($m[1] as $hole) {
                $out['roll_expression'] = array_merge($out['roll_expression'], Formula::lint($hole, $knownKeys));
            }
        }

        return array_filter($out);
    }
}
