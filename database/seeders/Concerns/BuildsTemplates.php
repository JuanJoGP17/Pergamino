<?php

namespace Database\Seeders\Concerns;

use App\Domain\Builder\FieldConfig;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\PublishTemplate;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateSection;
use App\Models\TemplateTab;
use App\Models\User;

/**
 * Lo que hace el constructor visual, en código: escribir filas en
 * template_tabs / sections / fields y publicar. Lo comparten los seeders de
 * las plantillas oficiales (5e, FATE, Vampiro, Blades).
 *
 * La configuración pasa por FieldConfig::clean, igual que la que llega del
 * inspector: un seeder no puede guardar nada que el constructor no aceptaría.
 */
trait BuildsTemplates
{
    /** El primer usuario, o uno «Sistema» si la base está vacía. */
    protected function owner(): User
    {
        return User::query()->orderBy('id')->first()
            ?? User::create([
                'name' => 'Sistema',
                'email' => 'sistema@pergamino.local',
                'password' => bin2hex(random_bytes(16)),
            ]);
    }

    /**
     * Crea la plantilla si no existe ya; null si existe (no se toca: puede
     * tener hojas apuntando a ella).
     */
    protected function newTemplate(string $slug, array $attributes): ?Template
    {
        if (Template::where('slug', $slug)->exists()) {
            $this->command?->info("La plantilla «{$attributes['name']}» ya existe; no se toca.");

            return null;
        }

        return Template::create([
            'owner_id' => $this->owner()->id,
            'slug' => $slug,
            'visibility' => 'public',
            'is_official' => true,
            ...$attributes,
        ]);
    }

    protected function publish(Template $template, string $label): void
    {
        $version = app(PublishTemplate::class)($template, $this->owner(), label: $label);

        $this->command?->info("Plantilla «{$template->name}» publicada (versión {$version->version}, "
            .count($version->compiled_schema['fields'] ?? []).' campos).');
    }

    protected function tab(Template $t, string $key, string $label, int $pos, bool $default = false): TemplateTab
    {
        return TemplateTab::create([
            'template_id' => $t->id,
            'key' => $key,
            'label' => $label,
            'position' => $pos,
            'is_default' => $default,
        ]);
    }

    protected function section(
        TemplateTab $tab,
        string $key,
        string $label,
        int $pos,
        int $columns = 1,
        ?string $visibleIf = null,
    ): TemplateSection {
        return TemplateSection::create([
            'template_tab_id' => $tab->id,
            'key' => $key,
            'label' => $label,
            'position' => $pos,
            'columns' => $columns,
            'visible_if' => $visibleIf,
        ]);
    }

    protected function field(
        Template $t,
        TemplateSection $s,
        string $key,
        string $label,
        string $type,
        int $pos,
        int $span,
        array $config = [],
        mixed $default = null,
        ?string $formula = null,
        ?string $roll = null,
        ?string $help = null,
        bool $summary = false,
        ?string $visibleIf = null,
        ?string $readonlyIf = null,
    ): TemplateField {
        return TemplateField::create([
            'template_id' => $t->id,
            'template_section_id' => $s->id,
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'position' => $pos,
            'col_span' => $span,
            'config' => FieldConfig::clean(FieldType::from($type), $config) ?: null,
            'default_value' => $default === null ? null : ['value' => $default],
            'formula' => $formula,
            'roll_expression' => $roll,
            'help_text' => $help,
            'is_summary' => $summary,
            'visible_if' => $visibleIf,
            'readonly_if' => $readonlyIf,
        ]);
    }
}
