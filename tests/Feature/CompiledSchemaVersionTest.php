<?php

use App\Domain\Formula\Interpolator;
use App\Domain\Formula\Parser;
use App\Domain\Schema\CompiledSchema;
use App\Domain\Schema\PublishTemplate;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Las versiones publicadas son inmutables y conservan el formato con que se
 * compilaron. El código de hoy tiene que seguir leyéndolas, y la caché nunca
 * debe servir un esquema que no corresponde.
 */
it('lee una tirada guardada con el formato 1 (marcadores \0)', function () {
    $legacy = [
        'schema_version' => 1,
        'fields' => ['destreza' => [
            'key' => 'destreza',
            'type' => 'attribute',
            'roll' => ['template' => "1d20 + \x000\x00 + \x001\x00", 'holes' => [Parser::parse('@destreza.mod'), Parser::parse('2')]],
        ]],
    ];

    $schema = CompiledSchema::fromArray($legacy);
    $roll = $schema->field('destreza')['roll'];

    expect($schema->schemaVersion)->toBe(CompiledSchema::VERSION)
        ->and($roll['parts'])->toBe(['1d20 + ', 0, ' + ', 1])
        ->and(Interpolator::run($roll, [], ['destreza' => ['mod' => 3]]))->toBe('1d20 + 3 + 2');
});

it('compila ya en el formato actual', function () {
    $user = User::factory()->create();
    $version = app(PublishTemplate::class)(makeTemplate([
        ['key' => 'destreza', 'type' => 'attribute', 'roll_expression' => '1d20 + {@destreza.mod}'],
    ]), $user);

    expect($version->compiled_schema['schema_version'])->toBe(CompiledSchema::VERSION)
        ->and($version->compiled_schema['fields']['destreza']['roll']['parts'])->toBe(['1d20 + ', 0]);
});

it('no sirve de la caché el esquema de otra fila con el mismo id', function () {
    // Lo que pasa tras un migrate:fresh con una caché que sobrevive: la
    // versión 1 vuelve a tener id 1, pero es otra plantilla.
    $user = User::factory()->create();
    $version = app(PublishTemplate::class)(makeTemplate([['key' => 'nueva', 'type' => 'text']]), $user);

    Cache::forever("tpl_v:{$version->id}", ['fields' => ['vieja' => ['key' => 'vieja', 'type' => 'text']]]);

    expect(TemplateVersion::find($version->id)->schema()->hasField('nueva'))->toBeTrue();
});
