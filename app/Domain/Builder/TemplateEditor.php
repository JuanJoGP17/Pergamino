<?php

namespace App\Domain\Builder;

use App\Domain\Schema\FieldType;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateSection;
use App\Models\TemplateTab;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operaciones del constructor visual sobre el BORRADOR de una plantilla
 * (las tablas template_tabs / sections / fields).
 *
 * Toda la lógica vive aquí y no en el componente Livewire: así se prueba sin
 * navegador, y el componente queda como una capa fina de interfaz.
 *
 * Reglas que garantiza:
 *   - Cada elemento que se toca pertenece a ESTA plantilla. El componente
 *     recibe ids desde el navegador; nunca se confía en ellos sin comprobarlo.
 *   - Las claves de campo son únicas en toda la plantilla (las fórmulas las
 *     usan sin prefijo) y las de pestaña y sección, únicas en su nivel.
 *   - Las posiciones quedan siempre 0, 1, 2… sin huecos.
 *   - Tocar el borrador actualiza templates.updated_at: así se sabe si hay
 *     cambios sin publicar.
 */
final class TemplateEditor
{
    private const KEY_MAX = 64;

    /** Palabras del motor que no pueden ser clave de campo (ver SchemaValidator). */
    private const RESERVED = ['self', 'row', 'true', 'false', 'null', 'if', 'and', 'or', 'not', 'in', 'contains'];

    // ============================================================ plantilla

    /** Plantilla en blanco con una pestaña y una sección, lista para recibir campos. */
    public function create(User $owner, string $name): Template
    {
        $name = trim($name) !== '' ? trim($name) : 'Plantilla sin nombre';

        return DB::transaction(function () use ($owner, $name) {
            $template = Template::create([
                'owner_id' => $owner->id,
                'name' => mb_substr($name, 0, 120),
                'slug' => Template::makeSlug($name),
                'game_line' => 'Casero',
                'visibility' => 'private',
                'settings' => ['mod_base' => 10, 'mod_divisor' => 2, 'prof_base' => 2, 'prof_step' => 4],
            ]);

            $tab = $this->addTab($template, 'General');
            $this->addSection($template, $tab->id, 'Principal');

            return $template->fresh();
        });
    }

    /** ¿Hay cambios en el borrador posteriores a la última versión publicada? */
    public static function hasUnpublishedChanges(Template $template): bool
    {
        $current = $template->currentVersion;

        return $current === null || $template->updated_at?->gt($current->published_at);
    }

    // ============================================================= pestañas

    public function addTab(Template $template, string $label = 'Nueva pestaña'): TemplateTab
    {
        $label = $this->cleanLabel($label, 'Nueva pestaña');

        $tab = TemplateTab::create([
            'template_id' => $template->id,
            'key' => $this->uniqueKey($label, fn ($k) => TemplateTab::where('template_id', $template->id)->where('key', $k)->exists(), 'pestana'),
            'label' => $label,
            'position' => TemplateTab::where('template_id', $template->id)->count(),
            'is_default' => ! TemplateTab::where('template_id', $template->id)->exists(),
        ]);

        $this->touch($template);

        return $tab;
    }

    public function updateTab(Template $template, int $tabId, array $attrs): TemplateTab
    {
        $tab = $this->tab($template, $tabId);
        $data = [];

        if (array_key_exists('label', $attrs)) {
            $data['label'] = $this->cleanLabel($attrs['label'], $tab->label);
        }

        if (array_key_exists('key', $attrs)) {
            $key = $this->normalizeKey((string) $attrs['key']);
            $taken = TemplateTab::where('template_id', $template->id)->where('key', $key)->where('id', '!=', $tab->id)->exists();

            if ($key === '' || $taken) {
                throw new BuilderException($key === '' ? 'La clave de la pestaña no puede quedar vacía.' : "Ya hay otra pestaña con la clave «{$key}».");
            }

            $data['key'] = $key;
        }

        $tab->update($data);
        $this->touch($template);

        return $tab;
    }

    public function deleteTab(Template $template, int $tabId): void
    {
        $tab = $this->tab($template, $tabId);

        if (TemplateTab::where('template_id', $template->id)->count() === 1) {
            throw new BuilderException('Una plantilla necesita al menos una pestaña.');
        }

        DB::transaction(function () use ($template, $tab) {
            $tab->delete();   // secciones y campos caen en cascada
            $this->renumber(TemplateTab::where('template_id', $template->id));
        });

        $this->touch($template);
    }

    public function moveTab(Template $template, int $tabId, int $position): void
    {
        $tab = $this->tab($template, $tabId);

        $this->reorder(TemplateTab::where('template_id', $template->id), $tab, $position);
        $this->touch($template);
    }

    // ============================================================ secciones

    public function addSection(Template $template, int $tabId, string $label = 'Nueva sección'): TemplateSection
    {
        $tab = $this->tab($template, $tabId);
        $label = $this->cleanLabel($label, 'Nueva sección');

        $section = TemplateSection::create([
            'template_tab_id' => $tab->id,
            'key' => $this->uniqueKey($label, fn ($k) => $this->sectionQuery($template)->where('template_sections.key', $k)->exists(), 'seccion'),
            'label' => $label,
            'position' => TemplateSection::where('template_tab_id', $tab->id)->count(),
            'columns' => 2,
        ]);

        $this->touch($template);

        return $section;
    }

    public function updateSection(Template $template, int $sectionId, array $attrs): TemplateSection
    {
        $section = $this->section($template, $sectionId);
        $data = [];

        foreach (['label', 'description'] as $text) {
            if (array_key_exists($text, $attrs)) {
                $value = trim((string) $attrs[$text]);
                $data[$text] = $value === '' ? null : mb_substr($value, 0, $text === 'label' ? 160 : 255);
            }
        }

        if (array_key_exists('key', $attrs)) {
            $key = $this->normalizeKey((string) $attrs['key']);
            $taken = $this->sectionQuery($template)->where('template_sections.key', $key)->where('template_sections.id', '!=', $section->id)->exists();

            if ($key === '' || $taken) {
                throw new BuilderException($key === '' ? 'La clave de la sección no puede quedar vacía.' : "Ya hay otra sección con la clave «{$key}».");
            }

            $data['key'] = $key;
        }

        if (array_key_exists('columns', $attrs)) {
            $data['columns'] = max(1, min(4, (int) $attrs['columns']));
        }

        if (array_key_exists('visible_if', $attrs)) {
            $data['visible_if'] = $this->nullableText($attrs['visible_if']);
        }

        foreach (['collapsible', 'collapsed_default'] as $flag) {
            if (array_key_exists($flag, $attrs)) {
                $data[$flag] = (bool) $attrs[$flag];
            }
        }

        $section->update($data);
        $this->touch($template);

        return $section;
    }

    public function deleteSection(Template $template, int $sectionId): void
    {
        $section = $this->section($template, $sectionId);

        DB::transaction(function () use ($section) {
            $tabId = $section->template_tab_id;
            $section->delete();   // los campos caen en cascada
            $this->renumber(TemplateSection::where('template_tab_id', $tabId));
        });

        $this->touch($template);
    }

    /** Mover una sección dentro de su pestaña o a otra. */
    public function moveSection(Template $template, int $sectionId, int $tabId, int $position): void
    {
        $section = $this->section($template, $sectionId);
        $target = $this->tab($template, $tabId);

        DB::transaction(function () use ($section, $target, $position) {
            $from = $section->template_tab_id;

            if ($from !== $target->id) {
                $section->update(['template_tab_id' => $target->id, 'position' => PHP_INT_MAX >> 48]);
                $this->renumber(TemplateSection::where('template_tab_id', $from));
            }

            $this->reorder(TemplateSection::where('template_tab_id', $target->id), $section->fresh(), $position);
        });

        $this->touch($template);
    }

    // =============================================================== campos

    /**
     * Añade un campo de un tipo. Etiqueta y clave salen del tipo y se hacen
     * únicas: «Número», «Número 2»… → numero, numero_2…
     */
    public function addField(Template $template, int $sectionId, string $type, ?int $position = null, ?string $label = null): TemplateField
    {
        $section = $this->section($template, $sectionId);
        $fieldType = FieldType::tryFrom($type);

        if (! $fieldType || ! $fieldType->isImplemented()) {
            throw new BuilderException("El tipo de campo «{$type}» no está disponible.");
        }

        $label = $this->cleanLabel($label ?? $fieldType->label(), $fieldType->label());

        return DB::transaction(function () use ($template, $section, $fieldType, $label, $position) {
            $field = TemplateField::create([
                'template_id' => $template->id,
                'template_section_id' => $section->id,
                'key' => $this->uniqueFieldKey($template, $label),
                'label' => $label,
                'type' => $fieldType->value,
                'position' => TemplateField::where('template_section_id', $section->id)->count(),
                'col_span' => FieldConfig::defaultSpan($fieldType),
                'config' => FieldConfig::defaults($fieldType) ?: null,
                'formula' => $fieldType === FieldType::Computed ? '0' : null,
            ]);

            if ($position !== null) {
                $this->reorder(TemplateField::where('template_section_id', $section->id), $field, $position);
            }

            $this->touch($template);

            return $field->fresh();
        });
    }

    /**
     * Añadir en bloque (§6.2, punto 3): una línea por campo, todos del mismo
     * tipo. Las 18 habilidades de 5e en una operación.
     *
     * @return array<int,TemplateField>
     */
    public function addFieldsInBulk(Template $template, int $sectionId, string $type, string $names): array
    {
        $labels = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $names))));

        if ($labels === []) {
            throw new BuilderException('Escribe al menos un nombre, uno por línea.');
        }

        if (count($labels) > 100) {
            throw new BuilderException('Como mucho 100 campos de una vez.');
        }

        return DB::transaction(fn () => array_map(
            fn (string $label) => $this->addField($template, $sectionId, $type, label: $label),
            $labels,
        ));
    }

    /**
     * Actualiza un campo con lo que llega del inspector. Solo se aceptan las
     * propiedades conocidas, cada una saneada a su tipo.
     */
    public function updateField(Template $template, int $fieldId, array $attrs): TemplateField
    {
        $field = $this->field($template, $fieldId);
        $data = [];

        if (array_key_exists('label', $attrs)) {
            $data['label'] = $this->cleanLabel($attrs['label'], $field->label);
        }

        if (array_key_exists('key', $attrs)) {
            $key = $this->normalizeKey((string) $attrs['key']);

            if ($key === '') {
                throw new BuilderException('La clave no puede quedar vacía.');
            }

            if (in_array($key, self::RESERVED, true)) {
                throw new BuilderException("«{$key}» es una palabra reservada del motor de fórmulas.");
            }

            if (TemplateField::where('template_id', $template->id)->where('key', $key)->where('id', '!=', $field->id)->exists()) {
                throw new BuilderException("Ya hay otro campo con la clave «{$key}». Las claves son únicas en toda la plantilla porque las fórmulas las usan.");
            }

            $data['key'] = $key;
        }

        if (array_key_exists('type', $attrs)) {
            $type = FieldType::tryFrom((string) $attrs['type']);

            if (! $type || ! $type->isImplemented()) {
                throw new BuilderException('Tipo de campo no disponible.');
            }

            if ($type->value !== $field->type) {
                // La configuración de un tipo no tiene sentido en otro.
                $data['type'] = $type->value;
                $data['config'] = FieldConfig::defaults($type) ?: null;
                $data['default_value'] = null;
                $data['formula'] = $type === FieldType::Computed ? ($field->formula ?: '0') : null;
            }
        }

        if (array_key_exists('help_text', $attrs)) {
            $help = trim((string) $attrs['help_text']);
            $data['help_text'] = $help === '' ? null : mb_substr($help, 0, 255);
        }

        if (array_key_exists('col_span', $attrs)) {
            $data['col_span'] = max(1, min(12, (int) $attrs['col_span']));
        }

        foreach (['formula', 'roll_expression', 'visible_if', 'readonly_if'] as $slot) {
            if (array_key_exists($slot, $attrs)) {
                $data[$slot] = $this->nullableText($attrs[$slot]);
            }
        }

        foreach (['is_required', 'is_summary'] as $flag) {
            if (array_key_exists($flag, $attrs)) {
                $data[$flag] = (bool) $attrs[$flag];
            }
        }

        $type = FieldType::from($data['type'] ?? $field->type);

        if (array_key_exists('config', $attrs) && is_array($attrs['config'])) {
            $data['config'] = FieldConfig::clean($type, $attrs['config']) ?: null;
        }

        if (array_key_exists('default_value', $attrs)) {
            $data['default_value'] = $this->cleanDefault($type, $attrs['default_value']);
        }

        $field->update($data);
        $this->touch($template);

        return $field->fresh();
    }

    public function deleteField(Template $template, int $fieldId): void
    {
        $field = $this->field($template, $fieldId);

        DB::transaction(function () use ($field) {
            $sectionId = $field->template_section_id;
            $field->delete();
            $this->renumber(TemplateField::where('template_section_id', $sectionId));
        });

        $this->touch($template);
    }

    /** Mover un campo dentro de su sección o a otra (de cualquier pestaña). */
    public function moveField(Template $template, int $fieldId, int $sectionId, int $position): void
    {
        $field = $this->field($template, $fieldId);
        $target = $this->section($template, $sectionId);

        DB::transaction(function () use ($field, $target, $position) {
            $from = $field->template_section_id;

            if ($from !== $target->id) {
                $field->update(['template_section_id' => $target->id, 'position' => PHP_INT_MAX >> 48]);
                $this->renumber(TemplateField::where('template_section_id', $from));
            }

            $this->reorder(TemplateField::where('template_section_id', $target->id), $field->fresh(), $position);
        });

        $this->touch($template);
    }

    /**
     * Duplicar (§6.2, punto 2): copia justo debajo, con «(copia)» en la
     * etiqueta y una clave nueva. Las fórmulas se copian tal cual.
     */
    public function duplicateField(Template $template, int $fieldId): TemplateField
    {
        $field = $this->field($template, $fieldId);

        return DB::transaction(function () use ($template, $field) {
            $copy = $field->replicate(['created_at', 'updated_at']);
            $copy->label = mb_substr($field->label.' (copia)', 0, 160);
            // fuerza_2 duplicado da fuerza_3, no fuerza_2_2.
            $copy->key = $this->uniqueFieldKey($template, preg_replace('/_\d+$/', '', $field->key) ?: $field->key);
            $copy->position = TemplateField::where('template_section_id', $field->template_section_id)->count();
            $copy->save();

            $this->reorder(TemplateField::where('template_section_id', $field->template_section_id), $copy, $field->position + 1);
            $this->touch($template);

            return $copy->fresh();
        });
    }

    // ============================================================== ajustes

    /**
     * Datos generales y ajustes que usan mod(), prof() y lookup().
     *
     * Las tablas de consulta llegan como JSON de texto desde el inspector:
     * { "dados_golpe": { "Mago": 6, "Guerrero": 10 } }.
     */
    public function updateSettings(Template $template, array $attrs): Template
    {
        $data = [];

        if (array_key_exists('name', $attrs)) {
            $data['name'] = $this->cleanLabel($attrs['name'], $template->name);
        }

        foreach (['tagline' => 160, 'game_line' => 80, 'description' => 5000] as $text => $max) {
            if (array_key_exists($text, $attrs)) {
                $value = trim((string) $attrs[$text]);
                $data[$text] = $value === '' ? null : mb_substr($value, 0, $max);
            }
        }

        if (array_key_exists('visibility', $attrs)) {
            if (! in_array($attrs['visibility'], Template::VISIBILITIES, true)) {
                throw new BuilderException('Visibilidad no válida.');
            }
            $data['visibility'] = $attrs['visibility'];
        }

        $settings = $template->settings ?? [];

        foreach (['mod_base', 'mod_divisor', 'prof_base', 'prof_step'] as $number) {
            if (array_key_exists($number, $attrs)) {
                $settings[$number] = is_numeric($attrs[$number]) ? $attrs[$number] + 0 : 0;
            }
        }

        if (array_key_exists('lookups', $attrs)) {
            $settings['lookups'] = $this->parseLookups((string) $attrs['lookups']);
        }

        $data['settings'] = $settings;

        $template->update($data);

        return $template->fresh();
    }

    // ============================================================ ayudantes

    private function tab(Template $template, int $id): TemplateTab
    {
        return TemplateTab::where('template_id', $template->id)->findOr($id, fn () => throw new BuilderException('Esa pestaña no es de esta plantilla.'));
    }

    private function section(Template $template, int $id): TemplateSection
    {
        $section = $this->sectionQuery($template)->select('template_sections.*')->where('template_sections.id', $id)->first();

        return $section ?? throw new BuilderException('Esa sección no es de esta plantilla.');
    }

    private function field(Template $template, int $id): TemplateField
    {
        return TemplateField::where('template_id', $template->id)->findOr($id, fn () => throw new BuilderException('Ese campo no es de esta plantilla.'));
    }

    private function sectionQuery(Template $template)
    {
        return TemplateSection::query()
            ->join('template_tabs', 'template_tabs.id', '=', 'template_sections.template_tab_id')
            ->where('template_tabs.template_id', $template->id);
    }

    /** Coloca $item en $position dentro de $siblings y renumera sin huecos. */
    private function reorder($siblings, Model $item, int $position): void
    {
        $ids = (clone $siblings)->orderBy('position')->orderBy('id')->pluck('id')->reject(fn ($id) => $id === $item->id)->values()->all();
        $position = max(0, min($position, count($ids)));
        array_splice($ids, $position, 0, [$item->id]);

        $this->applyOrder($item::class, $ids);
    }

    private function renumber($siblings): void
    {
        $model = $siblings->getModel()::class;
        $this->applyOrder($model, (clone $siblings)->orderBy('position')->orderBy('id')->pluck('id')->all());
    }

    /** @param array<int,int> $ids */
    private function applyOrder(string $model, array $ids): void
    {
        foreach ($ids as $position => $id) {
            $model::whereKey($id)->where('position', '!=', $position)->update(['position' => $position]);
        }
    }

    private function touch(Template $template): void
    {
        $template->touch();
    }

    private function cleanLabel(mixed $label, string $fallback): string
    {
        $label = trim((string) $label);

        return mb_substr($label !== '' ? $label : $fallback, 0, 160);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** «Puntos de Vida máx.» → puntos_de_vida_max */
    public function normalizeKey(string $text): string
    {
        $key = Str::of(Str::ascii($text))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        if ($key !== '' && ! preg_match('/^[a-z_]/', $key)) {
            $key = 'c_'.$key;   // no puede empezar por número
        }

        return mb_substr($key, 0, self::KEY_MAX);
    }

    private function uniqueFieldKey(Template $template, string $label): string
    {
        return $this->uniqueKey(
            $label,
            fn (string $k) => in_array($k, self::RESERVED, true)
                || TemplateField::where('template_id', $template->id)->where('key', $k)->exists(),
            'campo',
        );
    }

    /** @param callable(string):bool $taken */
    private function uniqueKey(string $label, callable $taken, string $fallback): string
    {
        $base = mb_substr($this->normalizeKey($label) ?: $fallback, 0, self::KEY_MAX - 4);

        $key = $base;
        for ($n = 2; $taken($key); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }

    /** El valor por defecto se guarda como {"value": …} con el tipo de su campo. */
    private function cleanDefault(FieldType $type, mixed $value): ?array
    {
        if ($value === null || $value === '' || ! $type->storesValue() || $type->isDerived()) {
            return null;
        }

        $value = match ($type) {
            FieldType::Number, FieldType::Attribute, FieldType::Counter,
            FieldType::Clock, FieldType::Progress => is_numeric($value) ? $value + 0 : null,
            FieldType::Checkbox => filter_var($value, FILTER_VALIDATE_BOOL),
            FieldType::Color => is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : null,
            FieldType::Text, FieldType::Textarea, FieldType::Select => mb_substr((string) $value, 0, 2000),
            // Los compuestos (recurso, marcas, tablas…) no tienen un valor por
            // defecto que se pueda escribir en una casilla de texto.
            default => null,
        };

        return $value === null ? null : ['value' => $value];
    }

    /** @return array<string,array<string,mixed>> */
    private function parseLookups(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new BuilderException('Las tablas de consulta deben ser un objeto JSON: { "tabla": { "clave": valor } }.');
        }

        foreach ($decoded as $name => $rows) {
            if (! is_array($rows) || (array_is_list($rows) && $rows !== [])) {
                throw new BuilderException("La tabla «{$name}» debe ser un objeto { \"clave\": valor }.");
            }

            foreach ($rows as $value) {
                if (is_array($value)) {
                    throw new BuilderException("En la tabla «{$name}» los valores deben ser números o textos, no listas.");
                }
            }
        }

        return $decoded;
    }
}
