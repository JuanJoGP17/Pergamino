<?php

use App\Domain\Builder\TemplateEditor;
use App\Livewire\Template\Builder;
use App\Livewire\Template\Index;
use App\Livewire\Template\Preview;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateTab;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * El constructor visual (Fase 3) a nivel de componente: lo que hace cada clic,
 * arrastre e input del inspector. El arrastre real en un navegador se prueba
 * aparte (ver SETUP.md); aquí se llama a lo que el arrastre llama.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->template = app(TemplateEditor::class)->create($this->user, 'Mi sistema');
    $this->section = $this->template->tabs()->first()->sections()->first();
});

function builder(): Testable
{
    return Livewire::actingAs(test()->user)->test(Builder::class, ['template' => test()->template]);
}

// ------------------------------------------------------------- listado

it('crea una plantilla desde el listado y abre el constructor', function () {
    Livewire::actingAs($this->user)->test(Index::class)
        ->set('name', 'Vampiro casero')
        ->call('create')
        ->assertRedirect(route('templates.builder', Template::where('name', 'Vampiro casero')->first()));
});

it('lista mis plantillas con su estado', function () {
    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('Mi sistema')
        ->assertSee('sin publicar');
});

// -------------------------------------------------------------- acceso

it('solo deja abrir el constructor a quien es dueño de la plantilla', function () {
    $this->get(route('templates.builder', $this->template))->assertRedirect(route('login'));

    Livewire::actingAs(User::factory()->create())
        ->test(Builder::class, ['template' => $this->template])
        ->assertForbidden();

    $this->actingAs($this->user)->get(route('templates.builder', $this->template))
        ->assertOk()
        ->assertSee('Mi sistema')
        ->assertSee('Atributo (valor + modificador)');   // la paleta
});

// --------------------------------------------------------------- campos

it('añade un campo desde la paleta y lo deja seleccionado en el inspector', function () {
    builder()->call('addField', 'number')
        ->assertSet('selection', 'field')
        ->assertSet('form.key', 'numero')
        ->assertSee('@numero');

    expect($this->section->fields()->count())->toBe(1);
});

it('añade un campo soltado desde la paleta en su posición exacta', function () {
    $editor = app(TemplateEditor::class);
    $editor->addField($this->template, $this->section->id, 'text', label: 'a');
    $editor->addField($this->template, $this->section->id, 'text', label: 'b');

    builder()->call('addFieldAt', 'checkbox', $this->section->id, 1);

    expect($this->section->fields()->pluck('key')->all())->toBe(['a', 'casilla', 'b']);
});

it('guarda cada propiedad del inspector al salir del input', function () {
    $b = builder()->call('addField', 'number')
        ->set('form.label', 'Nivel')
        ->set('form.key', 'nivel')
        ->set('form.config.min', '1')
        ->set('form.config.max', '20')
        ->set('form.col_span', '3');

    $field = TemplateField::where('key', 'nivel')->firstOrFail();

    expect($field->label)->toBe('Nivel')
        ->and($field->config)->toEqual(['min' => 1, 'max' => 20])
        ->and($field->col_span)->toBe(3);

    $b->assertSet('form.key', 'nivel');
});

it('explica por qué no acepta una clave repetida', function () {
    app(TemplateEditor::class)->addField($this->template, $this->section->id, 'number', label: 'Fuerza');

    builder()->call('addField', 'number')
        ->set('form.key', 'fuerza')
        ->assertHasErrors('form.key')
        ->assertSee('Ya hay otro campo con la clave «fuerza»')
        ->assertSet('form.key', 'numero');   // vuelve a la que tenía
});

it('señala bajo el input los problemas de una fórmula', function () {
    builder()->call('addField', 'computed')
        ->set('form.formula', '10 + @no_existe')
        ->assertSee('el campo «@no_existe» no existe en esta plantilla');
});

it('edita las opciones de una lista escribiéndolas una por línea', function () {
    builder()->call('addField', 'select')
        ->set('form.config.options', "Mago\nGuerrero | Guerrera")
        ->assertSet('form.config.options', "Mago\nGuerrero | Guerrera");

    expect(TemplateField::where('type', 'select')->first()->config['options'][1])
        ->toEqual(['value' => 'Guerrero', 'label' => 'Guerrera']);
});

it('añade campos en bloque a la sección seleccionada', function () {
    builder()->call('select', 'section', $this->section->id)
        ->set('bulkType', 'attribute')
        ->set('bulkNames', "Fuerza\nDestreza\nConstitución")
        ->call('bulkAdd')
        ->assertSee('3 campo(s) añadidos');

    expect($this->section->fields()->pluck('key')->all())->toBe(['fuerza', 'destreza', 'constitucion']);
});

it('duplica y borra el campo seleccionado', function () {
    $b = builder()->call('addField', 'number');
    $id = $b->get('selectedId');

    $b->call('duplicateField', $id)->assertSet('form.key', 'numero_2');
    $b->call('deleteSelected')->assertSet('selection', 'template');

    expect($this->section->fields()->pluck('key')->all())->toBe(['numero']);
});

it('pinta un botón de borrar en cada campo y sección del lienzo', function () {
    $b = builder()->call('addField', 'number');
    $id = $b->get('selectedId');

    $b->assertSeeHtml("wire:click.stop=\"delete('field', {$id})\"")
        ->assertSeeHtml("wire:click=\"delete('section', {$this->section->id})\"");
});

it('borra desde el lienzo un campo que no está seleccionado sin perder la selección', function () {
    $b = builder()->call('addField', 'number');
    $first = $b->get('selectedId');
    $b->call('addField', 'text');
    $second = $b->get('selectedId');

    $b->call('delete', 'field', $first)
        ->assertSet('selection', 'field')
        ->assertSet('selectedId', $second);

    expect($this->section->fields()->pluck('key')->all())->toBe(['texto_corto']);
});

it('al borrar una sección vuelve a la plantilla si lo seleccionado vivía dentro', function () {
    $b = builder()->call('addField', 'number');

    $b->call('delete', 'section', $this->section->id)->assertSet('selection', 'template');

    expect(TemplateField::where('template_id', $this->template->id)->count())->toBe(0);
});

it('borra pestañas desde el lienzo, salvo la última, que no ofrece el botón', function () {
    $first = $this->template->tabs()->first()->id;
    $b = builder()->assertDontSeeHtml("delete('tab', {$first})");

    $b->call('addTab');
    $second = $this->template->tabs()->where('id', '!=', $first)->value('id');
    $b->assertSeeHtml("wire:click.stop=\"delete('tab', {$first})\"")
        ->call('selectTab', $second)
        ->call('delete', 'tab', $second)
        ->assertSet('tabId', $first)
        ->assertSet('selection', 'template')
        ->assertDontSeeHtml("delete('tab', {$first})");

    $b->call('delete', 'tab', $first)->assertSet('notice.type', 'error');
    expect($this->template->tabs()->count())->toBe(1);
});

it('no borra un campo de otra plantilla', function () {
    $other = app(TemplateEditor::class)->create(User::factory()->create(), 'Ajena');
    $field = app(TemplateEditor::class)->addField($other, $other->tabs()->first()->sections()->first()->id, 'number');

    builder()->call('delete', 'field', $field->id)->assertSet('notice.type', 'error');

    expect(TemplateField::find($field->id))->not->toBeNull();
});

// ----------------------------------------------------------- estructura

it('añade pestañas y secciones y reordena los tres niveles', function () {
    $b = builder()->call('addTab');
    $newTab = TemplateTab::where('template_id', $this->template->id)->latest('id')->first();

    $b->assertSet('tabId', $newTab->id)->call('moveTab', $newTab->id, 0);
    expect($this->template->tabs()->pluck('id')->first())->toBe($newTab->id);

    $b->call('addSection');
    $sections = $newTab->sections()->get();
    $b->call('moveSection', $sections[1]->id, $newTab->id, 0);
    expect($newTab->sections()->pluck('id')->first())->toBe($sections[1]->id);

    $f1 = app(TemplateEditor::class)->addField($this->template, $sections[0]->id, 'text', label: 'uno');
    $b->call('moveField', $f1->id, $sections[1]->id, 0);
    expect($f1->fresh()->template_section_id)->toBe($sections[1]->id);
});

it('lleva una sección a otra pestaña desde el inspector, y el lienzo la sigue', function () {
    $tab2 = app(TemplateEditor::class)->addTab($this->template, 'Combate');

    builder()->call('select', 'section', $this->section->id)
        ->set('form.tab_id', $tab2->id)
        ->assertSet('tabId', $tab2->id);

    expect($this->section->fresh()->template_tab_id)->toBe($tab2->id);
});

it('convierte en aviso un id ajeno en vez de romper', function () {
    $ajena = app(TemplateEditor::class)->create(User::factory()->create(), 'Ajena');
    $suSeccion = $ajena->tabs()->first()->sections()->first();

    builder()->call('addFieldAt', 'text', $suSeccion->id, 0)
        ->assertSee('Esa sección no es de esta plantilla');

    expect($suSeccion->fields()->count())->toBe(0);
});

// --------------------------------------------- validación y publicación

it('no deja publicar con errores y sí cuando están corregidos', function () {
    $b = builder()->call('addField', 'computed')
        ->set('form.formula', '1 +')
        ->assertSee('error(es)');

    expect($b->html())->toMatch('/<button[^>]*disabled[^>]*>\s*Publicar…/');

    $b->call('publish')->assertSee('No se puede publicar');
    expect($this->template->fresh()->isPublished())->toBeFalse();

    $b->set('form.formula', '2 + 2')
        ->set('publishLabel', '1.0')
        ->call('publish')
        ->assertSee('Publicada la versión 1')
        ->assertSee('al día');

    expect($this->template->fresh()->currentVersion->label)->toBe('1.0');
});

it('selecciona el campo con problemas desde el panel de validación', function () {
    $f = app(TemplateEditor::class)->addField($this->template, $this->section->id, 'computed', label: 'Roto');
    app(TemplateEditor::class)->updateField($this->template, $f->id, ['formula' => '@nada']);

    builder()->call('selectFieldByKey', 'roto')
        ->assertSet('selection', 'field')
        ->assertSet('selectedId', $f->id);
});

it('no deja marcadores de Livewire dentro de las etiquetas del constructor', function () {
    $b = builder()->call('addField', 'attribute')->call('addField', 'select')->call('addField', 'computed');

    foreach (['template', 'section', 'field'] as $kind) {
        $html = $b->call('select', $kind, $kind === 'section' ? $this->section->id : $b->get('selectedId'))->html();
        expect($html)->not->toMatch('/<(input|select|textarea|section|div|span|button|nav|aside)\b[^>]*<!--/');
    }
});

// --------------------------------------------------------- vista previa

it('previsualiza el borrador sin publicar y recalcula con datos de prueba', function () {
    $editor = app(TemplateEditor::class);
    $nivel = $editor->addField($this->template, $this->section->id, 'number', label: 'Nivel');
    $prof = $editor->addField($this->template, $this->section->id, 'computed', label: 'Competencia');
    $editor->updateField($this->template, $prof->id, ['formula' => 'prof(@nivel)', 'config' => ['format' => 'mod']]);

    Livewire::actingAs($this->user)
        ->test(Preview::class, ['templateUuid' => $this->template->uuid])
        ->assertSee('Competencia')
        ->assertSee('+2')
        ->set('data.nivel', '9')
        ->assertSee('+4');

    expect($this->template->fresh()->isPublished())->toBeFalse();
});

it('la vista previa explica por qué no puede pintar un borrador con errores', function () {
    $f = app(TemplateEditor::class)->addField($this->template, $this->section->id, 'computed');
    app(TemplateEditor::class)->updateField($this->template, $f->id, ['formula' => '1 +']);

    Livewire::actingAs($this->user)
        ->test(Preview::class, ['templateUuid' => $this->template->uuid])
        ->assertSee('Todavía no se puede previsualizar');
});
