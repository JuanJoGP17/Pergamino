<?php

use App\Domain\Builder\Prefabs;
use App\Domain\Builder\TemplateEditor;
use App\Domain\Schema\PublishTemplate;
use App\Domain\Schema\SchemaValidator;
use App\Domain\Sheet\CreateSheet;
use App\Livewire\Sheet\Editor;
use App\Livewire\Template\Builder;
use App\Models\TemplateField;
use App\Models\User;
use Livewire\Livewire;

/**
 * Bloques prefabricados del constructor (§6.2, punto 4).
 */
beforeEach(function () {
    $this->editor = new TemplateEditor;
    $this->user = User::factory()->create();
    $this->template = $this->editor->create($this->user, 'Todo junto');
    $this->tab = $this->template->tabs()->first();

    // La sección «Principal» vacía de una plantilla nueva daría un aviso.
    $this->editor->deleteSection($this->template, $this->tab->sections()->first()->id);
});

it('todos los bloques juntos forman una plantilla que se publica sin errores', function () {
    foreach (array_keys(Prefabs::all()) as $block) {
        $this->editor->insertBlock($this->template, $this->tab->id, $block);
    }

    $issues = (new SchemaValidator)->validate($this->template->fresh());

    expect(array_column(array_filter($issues, fn ($i) => $i['level'] === 'error'), 'message'))->toBe([])
        ->and($this->tab->sections()->count())->toBe(count(Prefabs::all()));

    // Y con la plantilla publicada, la hoja se pinta entera sin romperse.
    (new PublishTemplate)($this->template->fresh());
    $sheet = app(CreateSheet::class)($this->template->fresh(), $this->user);

    $html = Livewire::actingAs($this->user)->test(Editor::class, ['sheet' => $sheet])->html();

    expect($html)->toContain('Trato con animales')
        ->toContain('Consecuencia grave (6)')
        ->toContain('Agravado')
        ->not->toMatch('/<(input|select|textarea|div|span|button|path|td)\b[^>]*<!--/');

    expect($sheet->computed['nivel'])->toBe(1)
        ->and($sheet->computed['competencia'])->toBe(2)
        ->and($sheet->computed['ca'])->toBe(10)
        ->and($sheet->computed['habilidades']['sigilo'])->toBe(['bonus' => 0])
        ->and($sheet->computed['salud'])->toBe(['boxes' => 4, 'marked' => 0])
        ->and($sheet->computed['voluntad'])->toBe(['boxes' => 2, 'marked' => 0])
        ->and($sheet->data['humanidad'])->toBe(7);
});

it('al repetir un bloque renombra sus claves y sus fórmulas siguen apuntando a sus campos', function () {
    $this->editor->insertBlock($this->template, $this->tab->id, 'inventario');
    $this->editor->insertBlock($this->template, $this->tab->id, 'inventario');

    $carga2 = TemplateField::where('template_id', $this->template->id)->where('key', 'carga_2')->sole();
    $inventario2 = TemplateField::where('template_id', $this->template->id)->where('key', 'inventario_2')->sole();

    expect($carga2->formula)->toBe('sum(@inventario_2[*].total)')
        // @row no es un campo: no se toca.
        ->and($inventario2->config['columns'][3]['formula'])->toBe('@row.peso * @row.cantidad');

    $issues = (new SchemaValidator)->validate($this->template->fresh());
    expect(array_filter($issues, fn ($i) => $i['level'] === 'error'))->toBe([]);
});

it('las referencias a campos de fuera del bloque se quedan y el validador las señala', function () {
    $this->editor->insertBlock($this->template, $this->tab->id, 'combate_d20');

    $messages = array_column((new SchemaValidator)->validate($this->template->fresh()), 'message');

    expect($messages)->toContain('En la fórmula de «ca»: el campo «@destreza» no existe en esta plantilla.');
});

it('se insertan desde la paleta del constructor', function () {
    Livewire::actingAs($this->user)
        ->test(Builder::class, ['template' => $this->template])
        ->assertSee('Salud y Voluntad (Vampiro)')
        ->call('insertBlock', 'salud_vampiro')
        ->assertSet('selection', 'section')
        ->assertSee('Bloque «Salud y Voluntad (Vampiro)» añadido.')
        ->call('insertBlock', 'no_existe')
        ->assertSee('Ese bloque no existe.');

    expect(TemplateField::where('template_id', $this->template->id)->where('key', 'salud')->sole()->config['boxes_formula'])
        ->toBe('@resistencia + 3');
});
