<?php

use App\Domain\Schema\PublishTemplate;
use App\Domain\Schema\SchemaCompilationException;
use App\Domain\Schema\SchemaCompiler;
use App\Domain\Sheet\CreateSheet;
use App\Models\TemplateField;
use App\Models\User;

it('aplana el árbol en un esquema con los campos indexados por clave', function () {
    $schema = (new SchemaCompiler)->compile(makeTemplate([
        ['key' => 'fuerza', 'type' => 'attribute'],
        ['key' => 'nombre', 'type' => 'text'],
    ]));

    expect($schema->hasField('fuerza'))->toBeTrue()
        ->and($schema->field('nombre')['type'])->toBe('text')
        ->and($schema->tabs)->toHaveCount(1)
        ->and($schema->tabs[0]['sections'][0]['field_keys'])->toBe(['fuerza', 'nombre']);
});

it('ordena los campos calculados según sus dependencias', function () {
    $schema = (new SchemaCompiler)->compile(makeTemplate([
        ['key' => 'cd', 'type' => 'computed', 'formula' => '8 + @competencia'],
        ['key' => 'competencia', 'type' => 'computed', 'formula' => '2 + floor((@nivel - 1) / 4)'],
        ['key' => 'nivel', 'type' => 'number'],
    ]));

    expect(array_search('competencia', $schema->computeOrder, true))
        ->toBeLessThan(array_search('cd', $schema->computeOrder, true));
});

it('no permite publicar una plantilla con fórmulas cíclicas', function () {
    $template = makeTemplate([
        ['key' => 'a', 'type' => 'computed', 'formula' => '@b + 1'],
        ['key' => 'b', 'type' => 'computed', 'formula' => '@a + 1'],
    ]);

    expect(fn () => (new SchemaCompiler)->compile($template))
        ->toThrow(SchemaCompilationException::class);
});

it('no permite publicar si una fórmula apunta a un campo inexistente', function () {
    $template = makeTemplate([
        ['key' => 'ca', 'type' => 'computed', 'formula' => '10 + @no_existe'],
    ]);

    expect(fn () => (new SchemaCompiler)->compile($template))
        ->toThrow(SchemaCompilationException::class);
});

it('genera valores por defecto para los campos que guardan valor', function () {
    $schema = (new SchemaCompiler)->compile(makeTemplate([
        ['key' => 'titulo', 'type' => 'heading'],
        ['key' => 'nombre', 'type' => 'text'],
        ['key' => 'fuerza', 'type' => 'attribute', 'default_value' => ['value' => 10]],
        ['key' => 'derivado', 'type' => 'computed', 'formula' => '@fuerza'],
    ]));

    $defaults = $schema->defaultData();

    expect($defaults)->toHaveKeys(['nombre', 'fuerza'])
        ->and($defaults)->not->toHaveKey('titulo')      // decorativo
        ->and($defaults)->not->toHaveKey('derivado')    // lo produce una fórmula
        ->and($defaults['fuerza'])->toBe(10);
});

it('publicar congela la versión y las hojas nuevas se anclan a ella', function () {
    $user = User::factory()->create();
    $template = makeTemplate([['key' => 'fuerza', 'type' => 'attribute', 'default_value' => ['value' => 14]]]);

    $v1 = app(PublishTemplate::class)($template, $user);
    $sheet = app(CreateSheet::class)($template->fresh(), $user, 'Eldra');

    expect($sheet->template_version_id)->toBe($v1->id)
        ->and($sheet->data['fuerza'])->toBe(14)
        ->and($sheet->computed['fuerza']['mod'])->toBe(2);

    // Añadir un campo y republicar no debe tocar la hoja existente.
    TemplateField::create([
        'template_id' => $template->id,
        'template_section_id' => $template->tabs->first()->sections->first()->id,
        'key' => 'destreza', 'label' => 'Destreza', 'type' => 'attribute',
        'position' => 1, 'col_span' => 12,
    ]);

    $v2 = app(PublishTemplate::class)($template->fresh(), $user);

    expect($v2->version)->toBe(2)
        ->and($sheet->fresh()->template_version_id)->toBe($v1->id)
        ->and($sheet->fresh()->schema()->hasField('destreza'))->toBeFalse();
});
