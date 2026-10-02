<?php

/**
 * Verificación aislada de las piezas puras del dominio.
 *
 * Se ejecuta con `php verify-domain.php`, SIN Laravel ni base de datos: sirve
 * para comprobar la lógica que no depende del framework antes de que exista un
 * entorno completo. Los tests de Pest en tests/Unit cubren lo mismo dentro de
 * la aplicación; esto es la red de seguridad de quien escribe el código.
 */
require __DIR__.'/verify-autoload.php';

use App\Domain\Formula\Formula;
use App\Domain\Formula\Interpolator;
use App\Domain\Schema\CompiledSchema;
use App\Domain\Schema\DependencyGraph;
use App\Domain\Schema\DependencyGraph as DG;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\FormulaReferences;
use App\Domain\Schema\SchemaCompilationException;
use App\Domain\Sheet\SheetCalculator;

$pass = 0;
$fail = 0;

function check(string $name, callable $fn): void
{
    global $pass, $fail;
    try {
        $result = $fn();
        if ($result === true) {
            $pass++;
            echo "  ok   {$name}\n";
        } else {
            $fail++;
            echo "  FAIL {$name}: ".var_export($result, true)."\n";
        }
    } catch (Throwable $e) {
        $fail++;
        echo "  FAIL {$name}: ".get_class($e).' — '.$e->getMessage()."\n";
    }
}

function eq(mixed $actual, mixed $expected): bool|string
{
    return $actual === $expected
        ? true
        : 'esperado '.json_encode($expected, JSON_UNESCAPED_UNICODE).', obtenido '.json_encode($actual, JSON_UNESCAPED_UNICODE);
}

echo "\nFormulaReferences\n";

check('extrae una referencia simple', fn () => eq(
    FormulaReferences::extract('@fuerza + 2'), ['fuerza']
));

check('quita la propiedad derivada', fn () => eq(
    FormulaReferences::extract('@fuerza.mod + @destreza.mod'), ['fuerza', 'destreza']
));

check('no duplica', fn () => eq(
    FormulaReferences::extract('@nivel + @nivel * 2'), ['nivel']
));

check('ignora arrobas dentro de cadenas', fn () => eq(
    FormulaReferences::extract('if(@clase == "mago@torre", 1, 0)'), ['clase']
));

// @self.nivel es un alias de @nivel, así que SÍ crea dependencia con «nivel»
// (y no con un campo llamado «self»). El escáner por regexp de la Fase 1 se
// comía esa dependencia, lo que habría dejado el orden de cálculo incompleto.
check('@self.x depende de x, no de «self»', fn () => eq(
    FormulaReferences::extract('@self.nivel + @fuerza'), ['nivel', 'fuerza']
));

check('«self» nunca aparece como campo requerido', function () {
    $refs = FormulaReferences::extract('@self.nivel + @self.fuerza');

    return in_array('self', $refs, true) ? 'self se coló: '.json_encode($refs) : true;
});

check('fórmula vacía no da referencias', fn () => eq(
    FormulaReferences::extract('   '), []
));

check('reúne las de todos los huecos del campo', fn () => eq(
    FormulaReferences::forField([
        'formula' => '@a + 1',
        'visible_if' => '@b > 0',
        'roll_expression' => '1d20 + {@c}',
        'readonly_if' => null,
    ]),
    ['a', 'b', 'c']
));

echo "\nDependencyGraph\n";

check('cadena lineal se ordena', fn () => eq(
    (new DependencyGraph([
        'ca' => ['destreza_mod'],
        'destreza_mod' => ['destreza'],
        'destreza' => [],
    ]))->topologicalOrder(),
    ['destreza', 'destreza_mod', 'ca']
));

check('dependencias externas al grafo se ignoran', fn () => eq(
    (new DependencyGraph([
        'iniciativa' => ['destreza'],   // destreza no es un nodo: campo normal
    ]))->topologicalOrder(),
    ['iniciativa']
));

check('nodos independientes salen en orden alfabético', fn () => eq(
    (new DependencyGraph(['zeta' => [], 'alfa' => [], 'medio' => []]))->topologicalOrder(),
    ['alfa', 'medio', 'zeta']
));

check('rombo: la dependencia común va primero', function () {
    $order = (new DependencyGraph([
        'd' => ['b', 'c'],
        'b' => ['a'],
        'c' => ['a'],
        'a' => [],
    ]))->topologicalOrder();

    return $order[0] === 'a' && $order[3] === 'd'
        ? true
        : 'orden inesperado: '.implode(',', $order);
});

check('auto-referencia no cuenta como ciclo', fn () => eq(
    (new DependencyGraph(['a' => ['a']]))->topologicalOrder(), ['a']
));

check('ciclo de dos lanza excepción', function () {
    try {
        (new DependencyGraph(['a' => ['b'], 'b' => ['a']]))->topologicalOrder();

        return 'no lanzó excepción';
    } catch (SchemaCompilationException $e) {
        return str_contains($e->getMessage(), 'ciclo') ? true : $e->getMessage();
    }
});

check('el mensaje del ciclo nombra los campos', function () {
    try {
        (new DependencyGraph([
            'fuerza' => ['ca'],
            'ca' => ['fuerza'],
            'suelto' => [],
        ]))->topologicalOrder();

        return 'no lanzó excepción';
    } catch (SchemaCompilationException $e) {
        return str_contains($e->getMessage(), 'fuerza') && str_contains($e->getMessage(), 'ca')
            ? true
            : $e->getMessage();
    }
});

check('ciclo largo también se detecta', function () {
    try {
        (new DependencyGraph(['a' => ['b'], 'b' => ['c'], 'c' => ['a']]))->topologicalOrder();

        return 'no lanzó excepción';
    } catch (SchemaCompilationException $e) {
        return true;
    }
});

check('grafo vacío devuelve lista vacía', fn () => eq(
    (new DependencyGraph)->topologicalOrder(), []
));

check('el orden es determinista entre ejecuciones', function () {
    $build = fn () => (new DependencyGraph([
        'x' => ['a'], 'y' => ['a'], 'z' => ['x', 'y'], 'a' => [],
    ]))->topologicalOrder();

    return $build() === $build() ? true : 'el orden varía';
});

echo "\nFieldType\n";

check('todos implementados salvo reference (Fase 4)', fn () => eq(
    count(FieldType::implemented()), count(FieldType::cases()) - 1
));

check('heading no guarda valor', fn () => eq(
    FieldType::Heading->storesValue(), false
));

check('computed es derivado', fn () => eq(
    FieldType::Computed->isDerived(), true
));

check('attribute expone .mod a las fórmulas', fn () => eq(
    FieldType::Attribute->derivedProperties(), ['mod']
));

check('resource arranca con la estructura correcta', fn () => eq(
    FieldType::Resource->emptyValue(), ['current' => 0, 'max' => 0, 'temp' => 0]
));

check('number arranca en cero', fn () => eq(FieldType::Number->emptyValue(), 0));
check('text arranca vacío', fn () => eq(FieldType::Text->emptyValue(), ''));
check('checkbox arranca en false', fn () => eq(FieldType::Checkbox->emptyValue(), false));

echo "\nSheetCalculator (modificador de atributo)\n";

$calc = new SheetCalculator;

check('16 → +3', fn () => eq($calc->attributeModifier(16), 3));
check('10 → +0', fn () => eq($calc->attributeModifier(10), 0));
check('8 → -1', fn () => eq($calc->attributeModifier(8), -1));
check('1 → -5 (redondeo hacia abajo con negativos)', fn () => eq($calc->attributeModifier(1), -5));
check('7 → -2', fn () => eq($calc->attributeModifier(7), -2));
check('20 → +5', fn () => eq($calc->attributeModifier(20), 5));
check('base y divisor configurables', fn () => eq(
    $calc->attributeModifier(12, ['mod_base' => 0, 'mod_divisor' => 3]), 4
));
check('divisor cero no revienta', fn () => eq(
    $calc->attributeModifier(12, ['mod_divisor' => 0]), 0
));
check('formatea el signo', fn () => eq(
    SheetCalculator::formatModifier(3).'/'.
    SheetCalculator::formatModifier(-1).'/'.
    SheetCalculator::formatModifier(0),
    '+3/-1/+0'
));

echo "\nIntegración: esquema compilado -> cálculo de hoja\n";

/**
 * Construye a mano un compiled_schema equivalente al que produciría
 * SchemaCompiler, para poder ejercitar toda la cadena sin base de datos.
 */
function schemaOf(array $fieldSpecs, array $settings = []): CompiledSchema
{
    $fields = [];
    $refs = [];

    foreach ($fieldSpecs as $key => $spec) {
        $ast = Formula::compile($spec['formula'] ?? null);
        $fields[$key] = [
            'key' => $key,
            'label' => ucfirst($key),
            'type' => $spec['type'],
            'col_span' => 12,
            'config' => $spec['config'] ?? [],
            'default_value' => $spec['default'] ?? null,
            'formula' => $spec['formula'] ?? null,
            'ast' => $ast,
            'visible_ast' => Formula::compile($spec['visible_if'] ?? null),
            'readonly_ast' => Formula::compile($spec['readonly_if'] ?? null),
            'roll' => Interpolator::compile($spec['roll'] ?? null),
            'is_summary' => false,
        ];

        $r = array_merge(
            Formula::references($ast),
            Formula::references($fields[$key]['visible_ast']),
        );

        if ($r !== [] || ($spec['formula'] ?? null)) {
            $refs[$key] = $r;
        }
    }

    return CompiledSchema::fromArray([
        'fields' => $fields,
        'compute_order' => (new DG($refs))->topologicalOrder(),
        'settings' => $settings,
        'tabs' => [],
    ]);
}

// Una hoja de 5e con la cadena completa de dependencias:
// nivel -> competencia -> cd_conjuros, y destreza -> ca
$schema = schemaOf([
    'nivel' => ['type' => 'number'],
    'fuerza' => ['type' => 'attribute'],
    'destreza' => ['type' => 'attribute'],
    'carisma' => ['type' => 'attribute'],
    'armadura' => ['type' => 'number'],
    'escudo' => ['type' => 'checkbox'],
    'competencia' => ['type' => 'computed', 'formula' => '2 + floor((@nivel - 1) / 4)'],
    'ca' => ['type' => 'computed', 'formula' => '10 + @destreza.mod + @armadura + if(@escudo, 2, 0)'],
    'cd_conjuros' => ['type' => 'computed', 'formula' => '8 + @competencia + @carisma.mod'],
    'iniciativa' => ['type' => 'computed', 'formula' => '@destreza.mod'],
    'roto' => ['type' => 'computed', 'formula' => '10 / @cero_inexistente'],
    'solo_magos' => ['type' => 'text', 'visible_if' => '@nivel >= 5'],
]);

$calc = new SheetCalculator;
$data = [
    'nivel' => 5, 'fuerza' => 16, 'destreza' => 14, 'carisma' => 18,
    'armadura' => 3, 'escudo' => true, 'solo_magos' => '',
];
[$computed, $errors] = $calc->calculateWithErrors($schema, $data);

check('el orden topológico pone competencia antes que cd_conjuros', function () use ($schema) {
    $order = $schema->computeOrder;

    return array_search('competencia', $order, true) < array_search('cd_conjuros', $order, true)
        ? true : 'orden: '.implode(',', $order);
});

check('modificadores de atributo calculados', fn () => eq(
    [$computed['fuerza']['mod'], $computed['destreza']['mod'], $computed['carisma']['mod']],
    [3, 2, 4]
));

check('competencia a nivel 5 = 3', fn () => eq($computed['competencia'], 3));
check('CA = 10 + 2 + 3 + 2 = 17', fn () => eq($computed['ca'], 17));
check('CD de conjuros = 8 + 3 + 4 = 15', fn () => eq($computed['cd_conjuros'], 15));
check('iniciativa = mod de destreza', fn () => eq($computed['iniciativa'], 2));

check('una fórmula rota deja el campo a null...', fn () => eq($computed['roto'], null));
check('...y registra su error sin tumbar el resto', function () use ($errors, $computed) {
    return isset($errors['roto']) && str_contains($errors['roto'], 'división por cero')
        && $computed['ca'] === 17
        ? true : json_encode($errors);
});

check('visible_if se cumple a nivel 5', fn () => eq(
    $calc->isVisible($schema->field('solo_magos'), $data, $computed), true
));

check('visible_if no se cumple a nivel 1', function () use ($calc, $schema) {
    $low = ['nivel' => 1, 'destreza' => 10, 'carisma' => 10, 'armadura' => 0, 'escudo' => false];
    [$c] = (new SheetCalculator)->calculateWithErrors($schema, $low);

    return eq($calc->isVisible($schema->field('solo_magos'), $low, $c), false);
});

check('cambiar un valor propaga por toda la cadena', function () use ($calc, $schema) {
    $subir = ['nivel' => 17, 'destreza' => 20, 'carisma' => 18, 'armadura' => 0, 'escudo' => false];
    [$c] = $calc->calculateWithErrors($schema, $subir);

    // nivel 17 -> competencia 6; destreza 20 -> mod 5; CA 10+5+0+0 = 15
    return eq([$c['competencia'], $c['ca'], $c['cd_conjuros']], [6, 15, 18]);
});

check('las referencias salen del AST, no de una regexp', function () {
    // Con el escáner de la Fase 1 esto inventaba una dependencia a «torre».
    return eq(Formula::references(Formula::compile('if(@clase == "mago@torre", 1, 0)')), ['clase']);
});

check('la interpolación de tiradas resuelve los huecos', function () use ($calc, $data, $computed) {
    $field = ['roll' => Interpolator::compile('1d20 + {@destreza.mod} + {@competencia}')];

    return eq($calc->rollExpression($field, $data, $computed), '1d20 + 2 + 3');
});

check('un ciclo de fórmulas se rechaza al construir el esquema', function () {
    try {
        schemaOf([
            'a' => ['type' => 'computed', 'formula' => '@b + 1'],
            'b' => ['type' => 'computed', 'formula' => '@a + 1'],
        ]);

        return 'no lanzó excepción';
    } catch (SchemaCompilationException $e) {
        return str_contains($e->getMessage(), 'ciclo') ? true : $e->getMessage();
    }
});

echo "\nLa plantilla de D&D 5e del seeder, evaluada de verdad\n";

$settings5e = [
    'mod_base' => 10, 'mod_divisor' => 2, 'prof_base' => 2, 'prof_step' => 4,
    'lookups' => ['dados_golpe' => ['Bardo' => 8, 'Mago' => 6, 'Guerrero' => 10]],
];

// Exactamente las fórmulas que escribe Dnd5eTemplateSeeder.
$s5 = schemaOf([
    'nivel' => ['type' => 'number'],
    'clase' => ['type' => 'text'],
    'destreza' => ['type' => 'attribute'],
    'sabiduria' => ['type' => 'attribute'],
    'carisma' => ['type' => 'attribute'],
    'competencia' => ['type' => 'computed', 'formula' => 'prof(@nivel)'],
    'iniciativa' => ['type' => 'computed', 'formula' => '@destreza.mod'],
    'percepcion_pasiva' => ['type' => 'computed', 'formula' => '10 + @sabiduria.mod'],
    'cd_conjuros' => ['type' => 'computed', 'formula' => '8 + @competencia + @carisma.mod'],
    'ataque_conjuros' => ['type' => 'computed', 'formula' => '@competencia + @carisma.mod'],
    'dado_golpe_clase' => ['type' => 'computed', 'formula' => 'concat("d", lookup("dados_golpe", @clase, 8))'],
], $settings5e);

$bardo = ['nivel' => 5, 'clase' => 'Bardo', 'destreza' => 14, 'sabiduria' => 12, 'carisma' => 18];
[$c5, $e5] = $calc->calculateWithErrors($s5, $bardo);

check('bardo de nivel 5: competencia +3', fn () => eq($c5['competencia'], 3));
check('iniciativa +2', fn () => eq($c5['iniciativa'], 2));
check('percepción pasiva 11', fn () => eq($c5['percepcion_pasiva'], 11));
check('CD de conjuros 15', fn () => eq($c5['cd_conjuros'], 15));
check('ataque con conjuros +7', fn () => eq($c5['ataque_conjuros'], 7));
check('dado de golpe de la tabla: d8', fn () => eq($c5['dado_golpe_clase'], 'd8'));
check('ninguna fórmula del seeder falla', fn () => eq($e5, []));

check('subir a nivel 17 propaga a competencia y CD', function () use ($calc, $s5, $bardo) {
    [$c] = $calc->calculateWithErrors($s5, ['nivel' => 17] + $bardo);

    return eq([$c['competencia'], $c['cd_conjuros']], [6, 18]);
});

check('cambiar de clase cambia el dado de golpe', function () use ($calc, $s5, $bardo) {
    [$c] = $calc->calculateWithErrors($s5, ['clase' => 'Mago'] + $bardo);

    return eq($c['dado_golpe_clase'], 'd6');
});

check('una clase fuera de la tabla usa el valor por defecto', function () use ($calc, $s5, $bardo) {
    [$c] = $calc->calculateWithErrors($s5, ['clase' => 'Artífice'] + $bardo);

    return eq($c['dado_golpe_clase'], 'd8');
});

echo "\n".str_repeat('─', 50)."\n";
echo $fail === 0
    ? "TODO OK — {$pass} comprobaciones\n\n"
    : "{$pass} ok, {$fail} FALLOS\n\n";

exit($fail === 0 ? 0 : 1);
