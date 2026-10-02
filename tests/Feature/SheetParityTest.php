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

it('calcula igual en los dos lados los tipos de rol de la Fase 4', function () {
    [, $schema] = publishWith([
        ['key' => 'nivel', 'label' => 'Nivel', 'type' => 'number'],
        ['key' => 'constitucion', 'label' => 'CON', 'type' => 'attribute', 'config' => cfg('attribute', [])],
        ['key' => 'pv', 'label' => 'PV', 'type' => 'resource', 'config' => cfg('resource', ['max_formula' => '@nivel * 7 + @constitucion.mod'])],
        ['key' => 'mana', 'label' => 'Maná', 'type' => 'resource', 'config' => cfg('resource', [])],
        ['key' => 'salud', 'label' => 'Salud', 'type' => 'track', 'config' => cfg('track', ['boxes_formula' => '@constitucion / 3 + 1', 'states' => "Superficial\nAgravado"])],
        ['key' => 'estres', 'label' => 'Estrés', 'type' => 'track', 'config' => cfg('track', ['boxes' => 4])],
        ['key' => 'xp', 'label' => 'PX', 'type' => 'progress', 'config' => cfg('progress', ['thresholds' => '0, 300, 900, 2700, 6500'])],
        ['key' => 'bolsa', 'label' => 'Bolsa', 'type' => 'currency', 'config' => cfg('currency', ['denominations' => "pc | Cobre | 1\npp | Plata | 10\npe | Electro | 0.5"])],
        ['key' => 'sigilo', 'label' => 'Sigilo', 'type' => 'proficiency', 'config' => cfg('proficiency', [
            'base' => '@constitucion.mod', 'levels' => "no | — | 0\ncomp | Comp. | prof(@nivel)\nexp | Exp. | prof(@nivel) * 2",
        ])],
        ['key' => 'habilidades', 'label' => 'Habilidades', 'type' => 'derived_list', 'config' => cfg('derived_list', [
            'items' => "atletismo | Atletismo | @constitucion.mod\nhistoria | Historia | 1 / 3",
            'levels' => "no | — | 0\ncomp | Comp. | prof(@nivel)",
        ])],
        ['key' => 'inv', 'label' => 'Inventario', 'type' => 'repeater', 'config' => cfg('repeater', [
            'columns' => "nombre | Nombre | texto\npeso | Peso | número\ncant | Cant. | número\ntotal | Total | = @row.peso * @row.cant\nmedia | Media | = @row.total / @row.cant",
        ])],
        ['key' => 'carga', 'label' => 'Carga', 'type' => 'computed', 'formula' => 'sum(@inv[*].total)'],
        ['key' => 'primera', 'label' => 'Primera', 'type' => 'computed', 'formula' => 'concat(@inv[0].nombre, ":", @inv[0].total)'],
        ['key' => 'herido', 'label' => 'Herido', 'type' => 'checkbox', 'visible_if' => '@pv.pct < 50 || @salud.marked > 2'],
        ['key' => 'golpe', 'label' => 'Golpe', 'type' => 'dice_button', 'roll_expression' => '1d20 + {@habilidades.atletismo.bonus} + {@xp.level}'],
    ]);

    $cases = [
        $schema->defaultData(),

        array_merge($schema->defaultData(), [
            'nivel' => '6', 'constitucion' => '15',
            'pv' => ['current' => '20', 'max' => 0, 'temp' => 3],
            'mana' => ['current' => 7, 'max' => 9, 'temp' => 0],
            'salud' => [1, 2, 2, 0, 1],
            'estres' => [1, 0, 1, 1],
            'xp' => '7000',
            'bolsa' => ['pc' => 7, 'pp' => '3', 'pe' => 3],
            'sigilo' => ['level' => 'exp', 'misc' => '-1'],
            'habilidades' => ['atletismo' => ['level' => 'comp', 'misc' => 0], 'historia' => ['level' => 'no', 'misc' => 2]],
            'inv' => [
                ['nombre' => 'Cuerda', 'peso' => '1.5', 'cant' => 3],
                ['nombre' => 'Vacío', 'peso' => 2, 'cant' => 0],   // media: división por cero
            ],
        ]),

        // Basura: no debe romper nada, y debe romper igual en los dos.
        array_merge($schema->defaultData(), [
            'nivel' => 'abc', 'pv' => 'no-es-un-objeto', 'salud' => null, 'xp' => [1, 2],
            'bolsa' => [], 'sigilo' => ['level' => 'inventado'], 'habilidades' => 'x',
            'inv' => [['peso' => 'x'], 'fila-rota', []],
        ]),
    ];

    $js = computeWithJs($schema, $cases);

    foreach ($cases as $i => $values) {
        expect($js[$i])->toBe(computeWithPhp($schema, $values), "caso #{$i}");
    }

    // Y no es que coincidan por estar vacíos: el caso 1 calcula de verdad.
    $php = computeWithPhp($schema, $cases[1]);
    expect($php['computed']['carga'])->toBe(4.5)
        ->and($php['computed']['pv'])->toBe(['max' => 44, 'pct' => 45])
        ->and($php['computed']['salud'])->toBe(['boxes' => 6, 'marked' => 4])
        ->and($php['computed']['bolsa'])->toBe(['total' => 38.5])
        ->and($php['computed']['sigilo'])->toBe(['bonus' => 7])
        ->and($php['computed']['primera'])->toBe('Cuerda:4.5')
        ->and($php['errors']['inv'])->toBe('división por cero')
        ->and($php['visible']['herido'])->toBeTrue()
        ->and($php['rolls']['golpe'])->toBe('1d20 + 5 + 5');
});
