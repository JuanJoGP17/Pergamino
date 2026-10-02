<?php

use App\Domain\Schema\PublishTemplate;
use App\Domain\Schema\SchemaCompilationException;
use App\Domain\Schema\SchemaCompiler;
use App\Domain\Schema\SchemaValidator;
use App\Domain\Sheet\CreateSheet;
use App\Domain\Sheet\SaveSheet;
use App\Domain\Sheet\SheetCalculator;
use App\Livewire\Sheet\Editor;
use App\Models\Template;
use App\Models\TemplateSection;
use App\Models\User;
use Database\Seeders\Dnd5eTemplateSeeder;
use Livewire\Livewire;

/**
 * Lo que la Fase 2 añade a los campos alrededor del motor: mod_formula,
 * visible_if de sección, readonly_if impuesto en el servidor y el editor
 * preparado para recalcular en el navegador.
 */

// ------------------------------------------------------------- mod_formula

it('calcula el modificador de un atributo con su mod_formula', function () {
    $schema = (new SchemaCompiler)->compile(makeTemplate([
        // Un sistema de 1 a 5 donde el modificador es el valor menos 3.
        ['key' => 'vigor', 'type' => 'attribute', 'config' => ['mod_formula' => '@self - 3']],
    ]));

    $computed = (new SheetCalculator)->calculate($schema, ['vigor' => 5]);

    expect($computed['vigor']['mod'])->toBe(2);
});

it('ata @self al propio campo al compilar, para que JS no tenga que saberlo', function () {
    $schema = (new SchemaCompiler)->compile(makeTemplate([
        ['key' => 'vigor', 'type' => 'attribute', 'config' => ['mod_formula' => 'floor(@self / 2)']],
    ]));

    expect($schema->field('vigor')['mod_ast']['args'][0]['l'])
        ->toMatchArray(['n' => 'ref', 'key' => 'vigor']);
});

it('ordena un mod_formula que depende de otro campo', function () {
    $schema = (new SchemaCompiler)->compile(makeTemplate([
        // El de la sabiduría depende de un calculado, que va antes.
        ['key' => 'bono_racial', 'type' => 'computed', 'formula' => 'if(@raza == "Elfo", 1, 0)'],
        ['key' => 'raza', 'type' => 'text'],
        ['key' => 'sabiduria', 'type' => 'attribute', 'config' => ['mod_formula' => 'mod(@self) + @bono_racial']],
        ['key' => 'percepcion', 'type' => 'computed', 'formula' => '10 + @sabiduria.mod'],
    ]));

    $order = $schema->computeOrder;
    expect(array_search('bono_racial', $order))->toBeLessThan(array_search('sabiduria', $order))
        ->and(array_search('sabiduria', $order))->toBeLessThan(array_search('percepcion', $order));

    $computed = (new SheetCalculator)->calculate($schema, ['raza' => 'Elfo', 'sabiduria' => 14]);

    expect($computed['sabiduria']['mod'])->toBe(3)
        ->and($computed['percepcion'])->toBe(13);
});

it('sin mod_formula usa la base y el divisor de la plantilla', function () {
    $template = makeTemplate([['key' => 'fuerza', 'type' => 'attribute']]);
    $template->update(['settings' => ['mod_base' => 0, 'mod_divisor' => 3]]);

    $schema = (new SchemaCompiler)->compile($template->fresh());

    expect((new SheetCalculator)->calculate($schema, ['fuerza' => 12])['fuerza']['mod'])->toBe(4);
});

it('un mod_formula roto deja el modificador vacío con su motivo, no la hoja', function () {
    $schema = (new SchemaCompiler)->compile(makeTemplate([
        ['key' => 'vigor', 'type' => 'attribute', 'config' => ['mod_formula' => '10 / (@self - 3)']],
    ]));

    [$computed, $errors] = (new SheetCalculator)->calculateWithErrors($schema, ['vigor' => 3]);

    expect($computed['vigor']['mod'])->toBeNull()
        ->and($errors['vigor'])->toContain('división por cero');
});

it('no deja publicar un mod_formula con referencias rotas', function () {
    $template = makeTemplate([
        ['key' => 'vigor', 'type' => 'attribute', 'config' => ['mod_formula' => '@no_existe + 1']],
    ]);

    expect(fn () => app(PublishTemplate::class)($template))
        ->toThrow(SchemaCompilationException::class);
});

// ---------------------------------------------------------- visible_if

it('compila y evalúa el visible_if de una sección', function () {
    $template = makeTemplate([['key' => 'clase', 'type' => 'text']]);
    TemplateSection::first()->update(['visible_if' => '@clase in ["Mago", "Bardo"]']);

    $schema = (new SchemaCompiler)->compile($template->fresh());
    $section = $schema->tabs[0]['sections'][0];
    $calc = new SheetCalculator;

    expect($section['visible_ast'])->not->toBeNull()
        ->and($calc->isSectionVisible($section, ['clase' => 'Mago'], []))->toBeTrue()
        ->and($calc->isSectionVisible($section, ['clase' => 'Guerrero'], []))->toBeFalse();
});

it('avisa de un visible_if de sección roto', function () {
    $template = makeTemplate([['key' => 'clase', 'type' => 'text']]);
    TemplateSection::first()->update(['visible_if' => '@clas == "Mago"']);

    $issues = (new SchemaValidator)->validate($template->fresh());

    expect(collect($issues)->pluck('message')->implode(' '))->toContain('«@clas» no existe');
});

// -------------------------------------------------------- readonly_if

it('impone readonly_if al guardar aunque el cliente mande el valor', function () {
    $user = User::factory()->create();
    $template = makeTemplate([
        ['key' => 'bloqueado', 'type' => 'checkbox'],
        ['key' => 'experiencia', 'type' => 'number', 'readonly_if' => '@bloqueado'],
    ]);
    app(PublishTemplate::class)($template, $user);
    $sheet = app(CreateSheet::class)($template->fresh(), $user);

    app(SaveSheet::class)($sheet, ['experiencia' => 300, 'bloqueado' => true], $user);
    expect($sheet->fresh()->data['experiencia'])->toBe(300);   // aún no estaba bloqueado

    app(SaveSheet::class)($sheet->fresh(), ['experiencia' => 99999], $user);
    expect($sheet->fresh()->data['experiencia'])->toBe(300);   // ahora sí
});

// --------------------------------------------------------------- editor

it('entrega al navegador los árboles para recalcular, no el texto', function () {
    $user = User::factory()->create();
    $template = makeTemplate([
        ['key' => 'nivel', 'type' => 'number'],
        ['key' => 'competencia', 'type' => 'computed', 'formula' => 'prof(@nivel)'],
    ]);
    app(PublishTemplate::class)($template, $user);
    $sheet = app(CreateSheet::class)($template->fresh(), $user);

    $component = Livewire::actingAs($user)->test(Editor::class, ['sheet' => $sheet]);
    $client = $component->instance()->clientSchema();

    expect($client['compute_order'])->toBe(['competencia'])
        ->and($client['fields']['competencia']['ast']['fn'])->toBe('prof')
        ->and($client['fields']['competencia'])->not->toHaveKey('formula');

    $component->assertSeeHtml('x-data="sheetFormulas(')
        ->assertSeeHtml('data-formula-key="nivel"')
        ->assertSeeHtml("x-text=\"display('competencia')\"");
});

it('pinta oculto lo que visible_if oculta y deshabilitado lo que readonly_if bloquea', function () {
    $user = User::factory()->create();
    $template = makeTemplate([
        ['key' => 'nivel', 'type' => 'number', 'default_value' => ['value' => 1]],
        ['key' => 'secreto', 'type' => 'text', 'visible_if' => '@nivel >= 5'],
        ['key' => 'xp', 'type' => 'number', 'readonly_if' => '@nivel >= 1'],
    ]);
    app(PublishTemplate::class)($template, $user);
    $sheet = app(CreateSheet::class)($template->fresh(), $user);

    $html = Livewire::actingAs($user)->test(Editor::class, ['sheet' => $sheet])->html();

    expect($html)->toMatch('/wire:key="field-secreto"[^>]*style="display: none;"/')
        ->and($html)->toMatch('/data-formula-key="xp"[^>]*disabled/');
});

it('muestra el motivo cuando una fórmula falla', function () {
    $user = User::factory()->create();
    $template = makeTemplate([
        ['key' => 'divisor', 'type' => 'number'],
        ['key' => 'ratio', 'type' => 'computed', 'formula' => '10 / @divisor'],
    ]);
    app(PublishTemplate::class)($template, $user);
    $sheet = app(CreateSheet::class)($template->fresh(), $user);
    app(SaveSheet::class)($sheet, ['divisor' => 0], $user);

    Livewire::actingAs($user)->test(Editor::class, ['sheet' => $sheet->fresh()])
        ->assertSee('división por cero');
});

it('adopta los datos tal como quedaron guardados tras guardar', function () {
    $user = User::factory()->create();
    $template = makeTemplate([
        ['key' => 'bloqueado', 'type' => 'checkbox', 'default_value' => ['value' => true]],
        ['key' => 'xp', 'type' => 'number', 'readonly_if' => '@bloqueado', 'default_value' => ['value' => 10]],
    ]);
    app(PublishTemplate::class)($template, $user);
    $sheet = app(CreateSheet::class)($template->fresh(), $user);

    Livewire::actingAs($user)->test(Editor::class, ['sheet' => $sheet])
        ->set('data.xp', 5000)
        ->assertSet('data.xp', 10);
});

it('no deja marcadores de Livewire dentro de ninguna etiqueta de la hoja de 5e', function () {
    $user = User::factory()->create();
    (new Dnd5eTemplateSeeder)->run();
    $sheet = app(CreateSheet::class)(Template::where('slug', 'dnd-5e')->first(), $user);

    $editor = Livewire::actingAs($user)->test(Editor::class, ['sheet' => $sheet]);

    foreach (['identidad', 'atributos', 'magia', 'notas'] as $tab) {
        // Un `<!--[if BLOCK]>` a media etiqueta convierte los atributos que
        // siguen en basura; el navegador no avisa, solo deja de funcionar.
        expect($editor->call('selectTab', $tab)->html())
            ->not->toMatch('/<(input|select|textarea|section|div|span|button)\b[^>]*<!--/');
    }
});
