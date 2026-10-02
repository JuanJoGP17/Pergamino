<?php

namespace App\Domain\Transfer;

use App\Domain\Builder\FieldConfig;
use App\Domain\Builder\TemplateEditor;
use App\Domain\Schema\FieldType;
use App\Domain\Theme\Theme;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateSection;
use App\Models\TemplateTab;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Exportar e importar plantillas en JSON (§6.2 punto 7 y §6.4).
 *
 * Formato versionado, el mismo que se importa:
 *
 *   {
 *     "format": "pergamino.template", "format_version": 1,
 *     "template": { name, tagline, description, game_line, settings, theme },
 *     "tabs": [ { key, label, sections: [ { key, label, description, columns,
 *                  visible_if, fields: [ { key, label, type, col_span, config,
 *                  default_value, formula, roll_expression, visible_if,
 *                  readonly_if, help_text, is_summary, is_required } ] } ] } ]
 *   }
 *
 * Se exporta el BORRADOR (lo que se edita en el constructor), no una versión
 * publicada: es lo que alguien querrá llevarse para seguir editando.
 *
 * Importar crea una plantilla nueva, sin publicar, del usuario que importa.
 * El archivo viene de fuera, así que se trata como lo que llega del
 * inspector: configuración por FieldConfig::clean, tema por lista blanca,
 * claves normalizadas y sin repetir, y límites de tamaño.
 */
final class TemplateTransfer
{
    public const FORMAT = 'pergamino.template';

    public const VERSION = 1;

    private const MAX_TABS = 30;

    private const MAX_SECTIONS = 200;

    private const MAX_FIELDS = 1500;

    public function export(Template $template): array
    {
        $template->loadMissing('tabs.sections.fields');

        return [
            'format' => self::FORMAT,
            'format_version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'template' => [
                'name' => $template->name,
                'tagline' => $template->tagline,
                'description' => $template->description,
                'game_line' => $template->game_line,
                'settings' => $template->settings ?? [],
                'theme' => $this->portableTheme($template->theme),
            ],
            'tabs' => $template->tabs->map(fn (TemplateTab $tab) => [
                'key' => $tab->key,
                'label' => $tab->label,
                'sections' => $tab->sections->map(fn (TemplateSection $s) => [
                    'key' => $s->key,
                    'label' => $s->label,
                    'description' => $s->description,
                    'columns' => (int) $s->columns,
                    'visible_if' => $s->visible_if,
                    'fields' => $s->fields->map(fn (TemplateField $f) => [
                        'key' => $f->key,
                        'label' => $f->label,
                        'type' => $f->type,
                        'col_span' => (int) $f->col_span,
                        'config' => $f->config ?? [],
                        'default_value' => $f->default_value['value'] ?? null,
                        'formula' => $f->formula,
                        'roll_expression' => $f->roll_expression,
                        'visible_if' => $f->visible_if,
                        'readonly_if' => $f->readonly_if,
                        'help_text' => $f->help_text,
                        'is_summary' => (bool) $f->is_summary,
                        'is_required' => (bool) $f->is_required,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array{0: Template, 1: array<int,string>} la plantilla y avisos
     *
     * @throws TransferException si el archivo no es una plantilla de Pergamino
     */
    public function import(User $owner, mixed $json): array
    {
        if (! is_array($json) || ($json['format'] ?? null) !== self::FORMAT) {
            throw new TransferException('El archivo no es una plantilla exportada de Pergamino.');
        }

        if ((int) ($json['format_version'] ?? 0) > self::VERSION) {
            throw new TransferException('La plantilla se exportó con una versión más nueva de Pergamino.');
        }

        $tabs = array_values(array_filter((array) ($json['tabs'] ?? []), 'is_array'));
        $sectionCount = array_sum(array_map(fn ($t) => count((array) ($t['sections'] ?? [])), $tabs));
        $fieldCount = array_sum(array_map(fn ($t) => array_sum(array_map(
            fn ($s) => count((array) ($s['fields'] ?? [])),
            array_filter((array) ($t['sections'] ?? []), 'is_array'),
        )), $tabs));

        if ($tabs === [] || count($tabs) > self::MAX_TABS || $sectionCount > self::MAX_SECTIONS || $fieldCount > self::MAX_FIELDS) {
            throw new TransferException('La plantilla está vacía o es demasiado grande para importarla.');
        }

        $meta = is_array($json['template'] ?? null) ? $json['template'] : [];
        $editor = new TemplateEditor;
        $warnings = [];

        $template = DB::transaction(function () use ($owner, $meta, $tabs, $editor, &$warnings) {
            $name = $this->text($meta['name'] ?? null, 120) ?? 'Plantilla importada';

            $template = Template::create([
                'owner_id' => $owner->id,
                'name' => $name,
                'slug' => Template::makeSlug($name),
                'tagline' => $this->text($meta['tagline'] ?? null, 160),
                'description' => $this->text($meta['description'] ?? null, 5000),
                'game_line' => $this->text($meta['game_line'] ?? null, 80) ?? 'Casero',
                'visibility' => 'private',
                'settings' => $this->settings($meta['settings'] ?? []),
                'theme' => $this->portableTheme($meta['theme'] ?? null) ?: null,
            ]);

            $tabKeys = [];
            $sectionKeys = [];
            $fieldKeys = [];

            foreach ($tabs as $t => $tabData) {
                $tab = TemplateTab::create([
                    'template_id' => $template->id,
                    'key' => $this->uniqueKey($editor, $tabData['key'] ?? $tabData['label'] ?? 'pestana', $tabKeys, 'pestana'),
                    'label' => $this->text($tabData['label'] ?? null, 120) ?? 'Pestaña',
                    'position' => $t,
                    'is_default' => $t === 0,
                ]);

                foreach (array_values(array_filter((array) ($tabData['sections'] ?? []), 'is_array')) as $s => $sectionData) {
                    $section = TemplateSection::create([
                        'template_tab_id' => $tab->id,
                        'key' => $this->uniqueKey($editor, $sectionData['key'] ?? $sectionData['label'] ?? 'seccion', $sectionKeys, 'seccion'),
                        'label' => $this->text($sectionData['label'] ?? null, 160),
                        'description' => $this->text($sectionData['description'] ?? null, 255),
                        'position' => $s,
                        'columns' => max(1, min(4, (int) ($sectionData['columns'] ?? 2))),
                        'visible_if' => $this->text($sectionData['visible_if'] ?? null, 2000),
                    ]);

                    $position = 0;
                    foreach (array_filter((array) ($sectionData['fields'] ?? []), 'is_array') as $fieldData) {
                        $type = FieldType::tryFrom((string) ($fieldData['type'] ?? ''));

                        if (! $type) {
                            $warnings[] = 'Se ha saltado el campo «'.($fieldData['label'] ?? $fieldData['key'] ?? '?').'»: tipo desconocido.';

                            continue;
                        }

                        $wanted = $editor->normalizeKey((string) ($fieldData['key'] ?? $fieldData['label'] ?? 'campo'));
                        $key = $this->uniqueKey($editor, $wanted, $fieldKeys, 'campo');

                        if ($key !== $wanted) {
                            $warnings[] = "La clave «{$wanted}» estaba repetida o reservada: el campo pasa a ser «{$key}». Revisa las fórmulas que la usen.";
                        }

                        $default = $fieldData['default_value'] ?? null;

                        TemplateField::create([
                            'template_id' => $template->id,
                            'template_section_id' => $section->id,
                            'key' => $key,
                            'label' => $this->text($fieldData['label'] ?? null, 160) ?? $type->label(),
                            'type' => $type->value,
                            'position' => $position++,
                            'col_span' => max(1, min(12, (int) ($fieldData['col_span'] ?? 12))),
                            'config' => FieldConfig::clean($type, is_array($fieldData['config'] ?? null) ? $fieldData['config'] : []) ?: null,
                            'default_value' => is_scalar($default) || is_array($default) ? ['value' => $default] : null,
                            'formula' => $this->text($fieldData['formula'] ?? null, 2000),
                            'roll_expression' => $this->text($fieldData['roll_expression'] ?? null, 500),
                            'visible_if' => $this->text($fieldData['visible_if'] ?? null, 2000),
                            'readonly_if' => $this->text($fieldData['readonly_if'] ?? null, 2000),
                            'help_text' => $this->text($fieldData['help_text'] ?? null, 255),
                            'is_summary' => (bool) ($fieldData['is_summary'] ?? false),
                            'is_required' => (bool) ($fieldData['is_required'] ?? false),
                        ]);
                    }
                }
            }

            return $template;
        });

        return [$template->fresh(), $warnings];
    }

    /**
     * El tema sin la imagen de fondo: es un archivo de quien lo subió, en esta
     * instalación, y no viaja en el JSON.
     */
    private function portableTheme(mixed $theme): array
    {
        $theme = Theme::sanitize($theme);
        unset($theme['custom_background']['media_id']);

        if (($theme['custom_background'] ?? null) === []) {
            unset($theme['custom_background']);
        }

        return $theme;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Claves únicas dentro de su ámbito; las reservadas del motor, no. */
    private function uniqueKey(TemplateEditor $editor, string $wanted, array &$taken, string $fallback): string
    {
        $base = mb_substr($editor->normalizeKey($wanted) ?: $fallback, 0, 60);
        $key = $base;

        for ($n = 2; isset($taken[$key]) || in_array($key, ['self', 'row', 'true', 'false', 'null', 'if', 'and', 'or', 'not', 'in', 'contains'], true); $n++) {
            $key = "{$base}_{$n}";
        }

        $taken[$key] = true;

        return $key;
    }

    /** Solo los ajustes que entiende el motor de fórmulas. */
    private function settings(mixed $settings): array
    {
        $settings = is_array($settings) ? $settings : [];
        $out = [];

        foreach (['mod_base', 'mod_divisor', 'prof_base', 'prof_step'] as $k) {
            if (is_numeric($settings[$k] ?? null)) {
                $out[$k] = $settings[$k] + 0;
            }
        }

        $lookups = [];
        foreach ((array) ($settings['lookups'] ?? []) as $name => $rows) {
            if (! is_string($name) || ! is_array($rows)) {
                continue;
            }

            $lookups[mb_substr($name, 0, 60)] = array_filter(
                array_slice($rows, 0, 500, true),
                fn ($v, $k) => (is_string($k) || is_int($k)) && (is_scalar($v) || $v === null),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        if ($lookups !== []) {
            $out['lookups'] = array_slice($lookups, 0, 50, true);
        }

        return $out;
    }
}
