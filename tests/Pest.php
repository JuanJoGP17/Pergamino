<?php

use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateSection;
use App\Models\TemplateTab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/**
 * Monta una plantilla mínima con una pestaña, una sección y los campos dados.
 * Es lo que hará el constructor visual en la Fase 3, en tres líneas.
 *
 * @param  array<int,array<string,mixed>>  $fields
 */
function makeTemplate(array $fields): Template
{
    $template = Template::factory()->create();

    $tab = TemplateTab::create([
        'template_id' => $template->id, 'key' => 'general', 'label' => 'General', 'position' => 0,
    ]);

    $section = TemplateSection::create([
        'template_tab_id' => $tab->id, 'key' => 'principal', 'label' => 'Principal', 'position' => 0,
    ]);

    foreach (array_values($fields) as $i => $field) {
        TemplateField::create(array_merge([
            'template_id' => $template->id,
            'template_section_id' => $section->id,
            'position' => $i,
            'col_span' => 12,
            'label' => ucfirst(str_replace('_', ' ', $field['key'])),
            'type' => 'number',
        ], $field));
    }

    return $template->fresh();
}
