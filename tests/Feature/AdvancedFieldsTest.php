<?php

use App\Domain\Builder\FieldConfig;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\SchemaCompilationException;
use App\Domain\Schema\SchemaCompiler;
use App\Domain\Schema\SchemaValidator;
use App\Domain\Sheet\CreateSheet;
use App\Domain\Sheet\FieldValue;
use App\Domain\Sheet\SaveSheet;
use App\Domain\Sheet\SheetCalculator;
use App\Models\Media;
use App\Models\User;

/**
 * Tipos de campo de rol (Fase 4): forma del valor, saneado al guardar,
 * propiedades derivadas y configuración desde el inspector.
 *
 * La paridad con el motor de JS de todo esto está en SheetParityTest. Los
 * ayudantes publishWith() y cfg() están en tests/Pest.php.
 */

// ------------------------------------------------------------ catálogo

it('tiene implementados todos los tipos salvo la referencia a otra hoja', function () {
    $missing = array_map(
        fn (FieldType $t) => $t->value,
        array_filter(FieldType::cases(), fn (FieldType $t) => ! $t->isImplemented()),
    );

    expect(array_values($missing))->toBe(['reference']);
});

// ------------------------------------------------------- forma del valor

it('da a cada tipo su valor inicial con la forma completa', function () {
    [, $schema] = publishWith([
        ['key' => 'pv', 'label' => 'PV', 'type' => 'resource', 'config' => cfg('resource', [])],
        ['key' => 'estres', 'label' => 'Estrés', 'type' => 'track', 'config' => cfg('track', ['boxes' => 3])],
        ['key' => 'reloj', 'label' => 'Reloj', 'type' => 'clock', 'config' => cfg('clock', ['segments' => 6])],
        ['key' => 'bolsa', 'label' => 'Bolsa', 'type' => 'currency', 'config' => cfg('currency', FieldConfig::defaults(FieldType::Currency))],
        ['key' => 'sigilo', 'label' => 'Sigilo', 'type' => 'proficiency', 'config' => cfg('proficiency', FieldConfig::defaults(FieldType::Proficiency))],
        ['key' => 'inv', 'label' => 'Inventario', 'type' => 'repeater', 'config' => cfg('repeater', ['columns' => "nombre | Nombre | texto\npeso | Peso | número", 'min_rows' => 1])],
        ['key' => 'rasgos', 'label' => 'Rasgos', 'type' => 'tags'],
        ['key' => 'tirada', 'label' => 'Tirada', 'type' => 'dice_button', 'roll_expression' => '1d20'],
    ]);

    expect($schema->defaultData())->toEqual([
        'pv' => ['current' => 0, 'max' => 0, 'temp' => 0],
        'estres' => [0, 0, 0],
        'reloj' => 0,
        'bolsa' => ['pc' => 0, 'pp' => 0, 'po' => 0],
        'sigilo' => ['level' => 'no', 'misc' => 0],
        'inv' => [['nombre' => '', 'peso' => null]],
        'rasgos' => [],
    ]);
});

it('sanea lo que llega del navegador a la forma de cada tipo', function () {
    $field = fn (string $type, array $config = []) => ['type' => $type, 'config' => $config];

    expect(FieldValue::normalize($field('resource'), ['current' => '50', 'max' => '30', 'temp' => '-4', 'hack' => 1]))
        ->toBe(['current' => 30, 'max' => 30, 'temp' => 0])
        ->and(FieldValue::normalize($field('resource', ['allow_overflow' => true]), ['current' => 50, 'max' => 30]))
        ->toBe(['current' => 50, 'max' => 30, 'temp' => 0])
        ->and(FieldValue::normalize($field('track', ['boxes' => 4, 'states' => ['Superficial', 'Agravado']]), [2, 9, 'x', 1, 1, 1]))
        ->toBe([2, 2, 0, 1])
        ->and(FieldValue::normalize($field('clock', ['segments' => 6]), 99))->toBe(6)
        ->and(FieldValue::normalize($field('counter', ['min' => 0, 'max' => 3]), '-2'))->toBe(0)
        ->and(FieldValue::normalize($field('multiselect', ['options' => [['value' => 'a'], ['value' => 'b']], 'max_selections' => 1]), ['b', 'zzz', 'a']))
        ->toBe(['b'])
        ->and(FieldValue::normalize($field('tags'), [' Valiente ', 'Valiente', '', ['x']]))->toBe(['Valiente'])
        ->and(FieldValue::normalize($field('color'), 'javascript:alert(1)'))->toBe('')
        ->and(FieldValue::normalize($field('color'), '#AABBCC'))->toBe('#aabbcc')
        ->and(FieldValue::normalize($field('number'), 'abc'))->toBeNull()
        ->and(FieldValue::normalize($field('select', ['options' => [['value' => 'Mago']]]), 'Rey'))->toBe('');
});

it('no deja que una tabla crezca sin límite ni guarde columnas inventadas', function () {
    $field = ['type' => 'repeater', 'config' => cfg('repeater', [
        'columns' => "nombre | Nombre | texto\ntotal | Total | = @row.peso * 2",
        'max_rows' => 2,
    ])];

    $rows = FieldValue::normalize($field, [
        ['nombre' => 'Espada', 'total' => 999, 'colado' => '<script>'],
        ['nombre' => 'Escudo'],
        ['nombre' => 'Tercera'],
    ]);

    expect($rows)->toBe([['nombre' => 'Espada'], ['nombre' => 'Escudo']]);
});

// --------------------------------------------------- propiedades derivadas

it('calcula las propiedades derivadas de los tipos de rol', function () {
    [, $schema] = publishWith([
        ['key' => 'nivel', 'label' => 'Nivel', 'type' => 'number'],
        ['key' => 'constitucion', 'label' => 'CON', 'type' => 'attribute', 'config' => cfg('attribute', [])],
        ['key' => 'pv', 'label' => 'PV', 'type' => 'resource', 'config' => cfg('resource', ['max_formula' => '@nivel * 8 + @constitucion.mod'])],
        ['key' => 'salud', 'label' => 'Salud', 'type' => 'track', 'config' => cfg('track', ['boxes_formula' => '@constitucion + 3', 'states' => "Superficial\nAgravado"])],
        ['key' => 'xp', 'label' => 'PX', 'type' => 'progress', 'config' => cfg('progress', ['thresholds' => '0, 300, 900, 2700'])],
        ['key' => 'bolsa', 'label' => 'Bolsa', 'type' => 'currency', 'config' => cfg('currency', ['denominations' => "pc | Cobre | 1\npo | Oro | 100"])],
        ['key' => 'sigilo', 'label' => 'Sigilo', 'type' => 'proficiency', 'config' => cfg('proficiency', [
            'base' => '@constitucion.mod',
            'levels' => "no | — | 0\ncomp | Competente | 2 + floor(@nivel / 5)",
        ])],
        ['key' => 'habilidades', 'label' => 'Habilidades', 'type' => 'derived_list', 'config' => cfg('derived_list', [
            'items' => "atletismo | Atletismo | @constitucion.mod\nhistoria | Historia",
            'levels' => "no | — | 0\nexperto | Experto | 4",
        ])],
        ['key' => 'inv', 'label' => 'Inventario', 'type' => 'repeater', 'config' => cfg('repeater', [
            'columns' => "nombre | Nombre | texto\npeso | Peso | número\ncantidad | Cant. | número\ntotal | Total | = @row.peso * @row.cantidad",
        ])],
        ['key' => 'carga', 'label' => 'Carga', 'type' => 'computed', 'formula' => 'sum(@inv[*].total)'],
        ['key' => 'resumen', 'label' => 'Resumen', 'type' => 'computed',
            'formula' => '@pv.pct + @salud.marked * 1000 + @xp.level * 100000 + @habilidades.atletismo.bonus * 10000000'],
    ]);

    $data = [
        'nivel' => 5, 'constitucion' => 14,
        'pv' => ['current' => 21, 'max' => 0, 'temp' => 0],
        'salud' => [1, 2, 0, 1, 0, 0, 1, 1],   // 17 casillas de tope por fórmula, 8 escritas
        'xp' => 1000,
        'bolsa' => ['pc' => 50, 'po' => 3],
        'sigilo' => ['level' => 'comp', 'misc' => 1],
        'habilidades' => ['atletismo' => ['level' => 'experto', 'misc' => 0], 'historia' => ['level' => 'no', 'misc' => -1]],
        'inv' => [['nombre' => 'Cuerda', 'peso' => 10, 'cantidad' => 1], ['nombre' => 'Raciones', 'peso' => 2, 'cantidad' => 5]],
    ];

    [$computed, $errors] = (new SheetCalculator)->calculateWithErrors($schema, $data);

    expect($errors)->toBe([])
        ->and($computed['pv'])->toBe(['max' => 42, 'pct' => 50])                 // 5×8 + 2
        ->and($computed['salud'])->toBe(['boxes' => 17, 'marked' => 5])
        ->and($computed['xp'])->toBe(['level' => 3, 'next' => 2700, 'pct' => 5])  // (1000-900)/(2700-900)
        ->and($computed['bolsa'])->toBe(['total' => 350])
        ->and($computed['sigilo'])->toBe(['bonus' => 6])                         // 2 + 3 + 1
        ->and($computed['habilidades'])->toBe(['atletismo' => ['bonus' => 6], 'historia' => ['bonus' => -1]])
        ->and($computed['inv'])->toBe([['total' => 10], ['total' => 10]])
        ->and($computed['carga'])->toBe(20)
        ->and($computed['resumen'])->toBe(60305050);
});

it('recorta el actual de un recurso contra el máximo calculado al guardar', function () {
    $user = User::factory()->create();
    [$template] = publishWith([
        ['key' => 'nivel', 'label' => 'Nivel', 'type' => 'number'],
        ['key' => 'pv', 'label' => 'PV', 'type' => 'resource', 'config' => cfg('resource', ['max_formula' => '@nivel * 10'])],
    ]);
    $template->update(['owner_id' => $user->id]);
    $sheet = (new CreateSheet)($template, $user);

    (new SaveSheet)($sheet, ['nivel' => 2, 'pv' => ['current' => 99, 'max' => 0, 'temp' => 0]], $user);

    expect($sheet->fresh()->data['pv'])->toEqual(['current' => 20, 'max' => 20, 'temp' => 0])
        ->and($sheet->fresh()->computed['pv'])->toEqual(['max' => 20, 'pct' => 100]);
});

it('solo deja poner en una hoja imágenes subidas por su dueño', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    [$template] = publishWith([['key' => 'retrato', 'label' => 'Retrato', 'type' => 'portrait', 'config' => cfg('portrait', [])]]);
    $sheet = (new CreateSheet)($template, $owner);

    $mine = Media::create(['user_id' => $owner->id, 'path' => 'a.webp', 'mime' => 'image/webp', 'size' => 1]);
    $theirs = Media::create(['user_id' => $other->id, 'path' => 'b.webp', 'mime' => 'image/webp', 'size' => 1]);

    (new SaveSheet)($sheet, ['retrato' => $theirs->id]);
    expect($sheet->fresh()->data['retrato'])->toBeNull();

    (new SaveSheet)($sheet, ['retrato' => (string) $mine->id]);
    expect($sheet->fresh()->data['retrato'])->toBe($mine->id);
});

it('una fórmula rota en una columna no tumba la hoja', function () {
    [, $schema] = publishWith([
        ['key' => 'inv', 'label' => 'Inventario', 'type' => 'repeater', 'config' => cfg('repeater', [
            'columns' => "peso | Peso | número\nratio | Ratio | = 10 / @row.peso",
        ])],
    ]);

    [$computed, $errors] = (new SheetCalculator)->calculateWithErrors($schema, ['inv' => [['peso' => 0], ['peso' => 5]]]);

    expect($computed['inv'])->toBe([['ratio' => null], ['ratio' => 2]])
        ->and($errors['inv'])->toBe('división por cero');
});

// ------------------------------------------------------------ validación

it('rechaza @row fuera de una columna y referencias rotas en la configuración', function () {
    $template = makeTemplate([
        ['key' => 'pv', 'label' => 'PV', 'type' => 'resource', 'config' => cfg('resource', ['max_formula' => '@row.x'])],
        ['key' => 'inv', 'label' => 'Inv', 'type' => 'repeater', 'config' => cfg('repeater', ['columns' => 'total | Total | = @row.a + @inexistente'])],
        ['key' => 'vacia', 'label' => 'Vacía', 'type' => 'repeater', 'config' => ['columns' => []]],
    ]);

    $messages = array_column((new SchemaValidator)->validate($template), 'message');

    expect($messages)->toContain('En la fórmula del máximo de «pv»: el campo «@row» no existe en esta plantilla.')
        ->and($messages)->toContain('En la columna «Total» de «inv»: el campo «@inexistente» no existe en esta plantilla.')
        ->and($messages)->toContain('La tabla «vacia» no tiene columnas.');

    expect(fn () => (new SchemaCompiler)->compile($template))->toThrow(SchemaCompilationException::class);
});

it('detecta ciclos que pasan por la configuración de un tipo de rol', function () {
    $template = makeTemplate([
        ['key' => 'pv', 'label' => 'PV', 'type' => 'resource', 'config' => cfg('resource', ['max_formula' => '@total'])],
        ['key' => 'total', 'label' => 'Total', 'type' => 'computed', 'formula' => '@pv.max + 1'],
    ]);

    expect(fn () => (new SchemaCompiler)->compile($template))->toThrow(SchemaCompilationException::class, 'pv');
});

// ----------------------------------------------------- inspector ↔ config

it('lee las listas del inspector como texto y las devuelve igual', function () {
    $config = cfg('repeater', ['columns' => "Nombre\npeso | Peso | número\ntipo | Tipo | lista: Arma, Armadura\nequipado | ¿Equipado? | casilla\ntotal | Total | = @row.peso * 2\nrow | Fila"]);

    expect($config['columns'])->toBe([
        ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
        ['key' => 'peso', 'label' => 'Peso', 'type' => 'number'],
        ['key' => 'tipo', 'label' => 'Tipo', 'type' => 'select', 'options' => ['Arma', 'Armadura']],
        ['key' => 'equipado', 'label' => '¿Equipado?', 'type' => 'checkbox'],
        ['key' => 'total', 'label' => 'Total', 'type' => 'computed', 'formula' => '@row.peso * 2'],
        ['key' => 'row_2', 'label' => 'Fila', 'type' => 'text'],   // «row» está reservada
    ]);

    $form = FieldConfig::toForm(FieldType::Repeater, $config);
    expect(cfg('repeater', $form)['columns'])->toBe($config['columns']);

    $levels = cfg('proficiency', ['levels' => "no | — | 0\nComp | Competente | @a || @b"])['levels'];
    expect($levels[1])->toBe(['key' => 'comp', 'label' => 'Competente', 'bonus' => '@a || @b'])
        ->and(cfg('progress', ['thresholds' => '900, 0; 300 300 x'])['thresholds'])->toBe([0, 300, 900])
        ->and(cfg('track', ['boxes' => 99, 'shape' => 'hexagon'])['boxes'])->toBe(30)
        ->and(cfg('track', ['shape' => 'hexagon'])['shape'])->toBe('box');
});
