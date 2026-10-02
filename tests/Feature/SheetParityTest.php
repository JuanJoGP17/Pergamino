<?php

use App\Domain\Sheet\SheetCalculator;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\Dnd5eTemplateSeeder;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Paridad PHP ↔ JS a nivel de HOJA ENTERA, sobre la plantilla real de 5e.
 *
 * formula-cases.json vigila fórmulas sueltas; esto vigila todo lo que hay
 * alrededor y que el editor usa para pintar al instante: orden topológico,
 * mod_formula, visible_if de campos y secciones, readonly_if, tiradas
 * interpoladas y el formato de presentación. Si el navegador enseñara un
 * número y el servidor guardara otro, saltaría aquí.
 */
beforeEach(function () {
    if ((new ExecutableFinder)->find('node') === null) {
        $this->markTestSkipped('Node no está instalado: no se puede ejecutar el motor de JS.');
    }

    User::factory()->create();
    (new Dnd5eTemplateSeeder)->run();

    $this->schema = Template::where('slug', 'dnd-5e')->firstOrFail()->currentVersion->schema();
});

/** Lo mismo que calcula compute-js.mjs, pero con el motor de PHP. */
function computeWithPhp($schema, array $values): array
{
    $calc = new SheetCalculator;
    [$computed, $errors] = $calc->calculateWithErrors($schema, $values);
    $result = compact('computed', 'errors') + ['visible' => [], 'readonly' => [], 'sections' => [], 'rolls' => [], 'display' => []];

    foreach ($schema->fields as $key => $field) {
        $result['visible'][$key] = $calc->isVisible($field, $values, $computed, $schema->settings);
        $result['readonly'][$key] = $calc->isReadonly($field, $values, $computed, $schema->settings);

        if (! empty($field['roll'])) {
            $result['rolls'][$key] = $calc->rollExpression($field, $values, $computed, $schema->settings);
        }

        if ($field['type'] === 'computed') {
            $result['display'][$key] = SheetCalculator::formatValue($computed[$key] ?? null, $field['config']['format'] ?? null);
        } elseif ($field['type'] === 'attribute') {
            $result['display'][$key] = SheetCalculator::formatModifier($computed[$key]['mod'] ?? null);
        }
    }

    foreach ($schema->tabs as $tab) {
        foreach ($tab['sections'] as $section) {
            $result['sections'][$section['key']] = $calc->isSectionVisible($section, $values, $computed, $schema->settings);
        }
    }

    // Mismo viaje por JSON que la salida de Node, para comparar igual con igual.
    return json_decode(json_encode($result), true);
}

function computeWithJs($schema, array $cases): array
{
    $process = new Process(['node', base_path('tests/fixtures/compute-js.mjs')]);
    $process->setInput(json_encode(['schema' => $schema->toArray(), 'cases' => $cases]));
    $process->mustRun();

    return json_decode($process->getOutput(), true);
}

it('calcula la hoja de 5e igual en el servidor y en el navegador', function () {
    $cases = [
        // Hoja recién creada: los valores por defecto.
        $this->schema->defaultData(),

        // Lanzador a nivel 5, con los valores como TEXTO, que es como llegan
        // de un <input> tanto a Livewire como al recalculo del navegador.
        array_merge($this->schema->defaultData(), [
            'clase' => 'Mago', 'nivel' => '5', 'inteligencia' => '18', 'destreza' => '14',
            'armadura_bonus' => '1', 'escudo' => true, 'dote_alerta' => true,
            'salv_inteligencia_comp' => true, 'salv_sabiduria_comp' => true,
        ]),

        // Guerrero de nivel 17 por hitos: sin magia, experiencia bloqueada.
        array_merge($this->schema->defaultData(), [
            'clase' => 'Guerrero', 'nivel' => 17, 'fuerza' => 20, 'constitucion' => 16,
            'progreso_por_hitos' => true, 'salv_fuerza_comp' => true, 'salv_constitucion_comp' => true,
        ]),

        // Valores basura: no deben romper nada, y deben romper igual en los dos.
        array_merge($this->schema->defaultData(), [
            'clase' => 'Inventada', 'nivel' => '', 'destreza' => 'abc', 'carisma' => null,
        ]),
    ];

    $js = computeWithJs($this->schema, $cases);

    foreach ($cases as $i => $values) {
        expect($js[$i])->toBe(computeWithPhp($this->schema, $values), "caso #{$i}");
    }
});

it('da los números correctos de 5e, no solo los mismos en los dos lados', function () {
    $values = array_merge($this->schema->defaultData(), [
        'clase' => 'Mago', 'nivel' => '5', 'inteligencia' => '18', 'destreza' => '14',
        'armadura_bonus' => '1', 'escudo' => true, 'dote_alerta' => true,
        'salv_inteligencia_comp' => true,
    ]);

    $php = computeWithPhp($this->schema, $values);

    expect($php['computed']['ca'])->toBe(15)                       // 10 + 2 + 1 + 2
        ->and($php['computed']['competencia'])->toBe(3)
        ->and($php['computed']['iniciativa'])->toBe(7)             // 2 + Alerta
        ->and($php['computed']['salv_inteligencia'])->toBe(7)      // 4 + 3
        ->and($php['computed']['salv_fuerza'])->toBe(0)
        ->and($php['computed']['aptitud_conjuros'])->toBe('inteligencia')
        ->and($php['computed']['cd_conjuros'])->toBe(15)           // 8 + 3 + 4
        ->and($php['display']['ataque_conjuros'])->toBe('+7')
        ->and($php['rolls']['salv_inteligencia'])->toBe('1d20 + 7')
        ->and($php['sections']['conjuros'])->toBeTrue()
        ->and($php['errors'])->toBe([]);
});
