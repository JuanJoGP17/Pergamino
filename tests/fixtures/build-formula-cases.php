<?php

/**
 * Genera tests/fixtures/formula-cases.json.
 *
 * Los casos se escriben aquí como TEXTO de fórmula. El generador los compila
 * con el analizador de PHP y guarda el AST junto al resultado esperado, porque
 * el motor de JavaScript no analiza: solo evalúa árboles.
 *
 * Ejecutar tras cualquier cambio en el lenguaje:
 *
 *     php tests/fixtures/build-formula-cases.php
 *     php tests/fixtures/run-php.php
 *     node tests/fixtures/run-js.mjs
 */

require __DIR__.'/../../verify-autoload.php';

use App\Domain\Formula\Formula;
use App\Domain\Formula\FormulaRuntimeException;

/**
 * Contexto por defecto: una hoja de 5e a medio rellenar. Casi todos los casos
 * pueden usarlo, y así se prueba también la resolución de referencias.
 */
$VALUES = [
    'nivel' => 5,
    'fuerza' => 16,
    'destreza' => 14,
    'constitucion' => 13,
    'sabiduria' => 12,
    'carisma' => 18,
    'inteligencia' => 8,
    'clase' => 'Bardo',
    'escudo' => true,
    'armadura_bonus' => 3,
    'vacio' => null,
    'cero' => 0,
    'texto_cero' => '0',
    'texto_num' => '42',
    'dotes' => ['Alerta', 'Centinela'],
    'sin_dotes' => [],
    'inventario' => [
        ['nombre' => 'Espada', 'peso' => 3, 'cantidad' => 1],
        ['nombre' => 'Racion', 'peso' => 2, 'cantidad' => 5],
    ],
];

$COMPUTED = [
    'fuerza' => ['mod' => 3],
    'destreza' => ['mod' => 2],
    'constitucion' => ['mod' => 1],
    'sabiduria' => ['mod' => 1],
    'carisma' => ['mod' => 4],
    'inteligencia' => ['mod' => -1],
    'competencia' => 3,
];

$SETTINGS = [
    'lookups' => [
        'dados_golpe' => ['Bardo' => 8, 'Guerrero' => 10, 'Mago' => 6],
    ],
];

/** @var array<int,array{group:string,formula:string,expect?:mixed,error?:bool}> */
$cases = [];

$case = function (string $group, string $formula, mixed $expect) use (&$cases) {
    $cases[] = ['group' => $group, 'formula' => $formula, 'expect' => $expect];
};

$fails = function (string $group, string $formula) use (&$cases) {
    $cases[] = ['group' => $group, 'formula' => $formula, 'error' => true];
};

// ---------------------------------------------------------------- aritmética
$case('aritmética', '1 + 2', 3);
$case('aritmética', '10 - 4', 6);
$case('aritmética', '3 * 4', 12);
$case('aritmética', '10 / 4', 2.5);
$case('aritmética', '10 / 5', 2);
$case('aritmética', '10 % 3', 1);
$case('aritmética', '-7 % 3', -1);          // conserva el signo del dividendo
$case('aritmética', '2 + 3 * 4', 14);       // precedencia
$case('aritmética', '(2 + 3) * 4', 20);
$case('aritmética', '-5 + 3', -2);
$case('aritmética', '- -5', 5);
$case('aritmética', '+7', 7);
$case('aritmética', '2 * -3', -6);
$case('aritmética', '1.5 + 1.5', 3);        // integral → entero
$case('aritmética', '0.1 + 0.2', 0.30000000000000004);  // el doble es el doble
$fails('aritmética', '1 / 0');
$fails('aritmética', '1 % 0');
$fails('aritmética', '1 / @cero');

// ------------------------------------------------------------- comparaciones
$case('comparación', '3 > 2', true);
$case('comparación', '3 >= 3', true);
$case('comparación', '2 < 1', false);
$case('comparación', '2 <= 2', true);
$case('comparación', '2 == 2', true);
$case('comparación', '2 == "2"', true);      // laxa
$case('comparación', '"a" == "a"', true);
$case('comparación', '"a" == "b"', false);
$case('comparación', '0 == null', true);
$case('comparación', 'null == null', true);
$case('comparación', '1 != 2', true);
$case('comparación', 'true == 1', true);
$case('comparación', 'false == 0', true);
$case('comparación', '"10" > "9"', true);    // ambos numéricos → numérico
$case('comparación', '"b" > "a"', true);     // no numéricos → alfabético

// ------------------------------------------------------------------- lógicos
$case('lógica', 'true && false', false);
$case('lógica', 'true || false', true);
$case('lógica', '!true', false);
$case('lógica', 'not false', true);
$case('lógica', 'true and true', true);
$case('lógica', 'false or true', true);
$case('lógica', '!0', true);
$case('lógica', '!""', true);
$case('lógica', '!"0"', false);              // "0" es truthy, ver README
$case('lógica', '![]', true);
// Cortocircuito: sin él, esto dividiría por cero.
$case('lógica', '@cero != 0 && 10 / @cero > 1', false);
$case('lógica', '@nivel > 0 || 10 / @cero > 1', true);

// ------------------------------------------------------------------ ternario
$case('ternario', 'true ? 1 : 2', 1);
$case('ternario', 'false ? 1 : 2', 2);
$case('ternario', '@nivel > 3 ? "alto" : "bajo"', 'alto');
$case('ternario', '@cero ? 1 : 2', 2);
$case('ternario', '1 > 0 ? (2 > 1 ? "a" : "b") : "c"', 'a');

// --------------------------------------------------------------- referencias
$case('referencias', '@fuerza', 16);
// @self es un alias del propio contexto: @self.x === @x
$case('referencias', '@self.nivel', 5);
$case('referencias', '@self.fuerza + 1', 17);
$case('referencias', '@self', null);
$case('referencias', '@fuerza.mod', 3);
$case('referencias', '@inteligencia.mod', -1);
$case('referencias', '@competencia', 3);
$case('referencias', '@vacio', null);
$case('referencias', '@vacio + 5', 5);              // null vale 0
$case('referencias', '@no_existe_en_contexto', null);
$case('referencias', '@fuerza.no_existe', null);
$case('referencias', '@inventario[0].nombre', 'Espada');
$case('referencias', '@inventario[1].peso', 2);
$case('referencias', '@inventario[9].peso', null);   // fuera de rango
$case('referencias', '@inventario[*].peso', [3, 2]);
$case('referencias', 'sum(@inventario[*].peso)', 5);
$case('referencias', 'count(@inventario)', 2);

// -------------------------------------------------------------------- textos
$case('texto', '"hola"', 'hola');
$case('texto', "'hola'", 'hola');
$case('texto', '"hola" + " mundo"', 'hola mundo');
$case('texto', '"nivel " + @nivel', 'nivel 5');
$case('texto', 'concat("a", "b", @nivel)', 'ab5');
$case('texto', 'upper("abc")', 'ABC');
$case('texto', 'lower("ABC")', 'abc');
$case('texto', 'len("hola")', 4);
$case('texto', 'len(@dotes)', 2);
$case('texto', '"1" + "2"', 3);              // ambos numéricos → suma
$case('texto', '"a" + 1', 'a1');
$case('texto', '@texto_num + 1', 43);        // cadena numérica → número

// ----------------------------------------------------------------- funciones
$case('funciones', 'floor(3.7)', 3);
$case('funciones', 'floor(-3.2)', -4);
$case('funciones', 'ceil(3.2)', 4);
$case('funciones', 'ceil(-3.7)', -3);
$case('funciones', 'round(3.5)', 4);
$case('funciones', 'round(-3.5)', -4);       // medio hacia arriba también en negativo
$case('funciones', 'round(2.4)', 2);
$case('funciones', 'round(3.14159, 2)', 3.14);
$case('funciones', 'abs(-5)', 5);
$case('funciones', 'sign(-3)', -1);
$case('funciones', 'sign(0)', 0);
$case('funciones', 'sqrt(16)', 4);
$case('funciones', 'sqrt(-1)', 0);           // sin números complejos aquí
$case('funciones', 'pow(2, 10)', 1024);
$case('funciones', 'min(3, 1, 2)', 1);
$case('funciones', 'max(3, 1, 2)', 3);
// min/max operan sobre números: una lista de textos se coacciona a ceros.
// No es un accidente, es la regla de coerción aplicada; queda fijada aquí.
$case('funciones', 'min(["Alerta", "Centinela"])', 0);
$case('funciones', 'max([3, "7", true])', 7);
$case('funciones', 'clamp(15, 1, 10)', 10);
$case('funciones', 'clamp(-5, 1, 10)', 1);
$case('funciones', 'clamp(5, 1, 10)', 5);
$case('funciones', 'sum(1, 2, 3)', 6);
$case('funciones', 'sum(@inventario[*].cantidad)', 6);
$case('funciones', 'avg(2, 4, 6)', 4);
$case('funciones', 'avg(1, 2)', 1.5);
$case('funciones', 'count(@sin_dotes)', 0);
$case('funciones', 'any(@dotes)', true);
$case('funciones', 'any(@sin_dotes)', false);
$case('funciones', 'all(@dotes)', true);
$case('funciones', 'if(true, "sí", "no")', 'sí');
$case('funciones', 'if(@nivel > 10, "alto", "bajo")', 'bajo');
// if es perezoso: la rama no elegida ni se evalúa, así no divide por cero.
$case('funciones', 'if(@cero == 0, 0, 100 / @cero)', 0);
$case('funciones', 'coalesce(@vacio, @nivel)', 5);
$case('funciones', 'coalesce(@vacio, "", 7)', 7);
$case('funciones', 'switch(@clase, "Mago", 6, "Bardo", 8, 10)', 8);
$case('funciones', 'switch("Pícaro", "Mago", 6, "Bardo", 8, 10)', 10);
$case('funciones', 'switch("Pícaro", "Mago", 6, "Bardo", 8)', null);
$case('funciones', 'tier(7, [5, 10, 15])', 1);
$case('funciones', 'tier(20, [5, 10, 15])', 3);
$case('funciones', 'tier(1, [5, 10, 15])', 0);
$case('funciones', 'lookup("dados_golpe", @clase)', 8);
$case('funciones', 'lookup("dados_golpe", "Ninguna", 4)', 4);
$case('funciones', 'lookup("tabla_inexistente", "x", 99)', 99);
$case('funciones', 'avg_of("2d6+3")', 10);
$case('funciones', 'avg_of("1d20")', 10.5);
$case('funciones', 'avg_of("4d6")', 14);
$fails('funciones', 'floor()');
$fails('funciones', 'floor(1, 2)');
$fails('funciones', 'no_existe(1)');

// --------------------------------------------------------- mod() y prof() 5e
$case('rol', 'mod(16)', 3);
$case('rol', 'mod(10)', 0);
$case('rol', 'mod(8)', -1);
$case('rol', 'mod(7)', -2);
$case('rol', 'mod(1)', -5);
$case('rol', 'mod(@fuerza)', 3);
$case('rol', 'prof(1)', 2);
$case('rol', 'prof(4)', 2);
$case('rol', 'prof(5)', 3);
$case('rol', 'prof(20)', 6);
$case('rol', 'prof(@nivel)', 3);

// ----------------------------------------------------------- listas y "in"
$case('listas', '[1, 2, 3]', [1, 2, 3]);
$case('listas', '[]', []);
$case('listas', '"Alerta" in @dotes', true);
$case('listas', '"Suerte" in @dotes', false);
$case('listas', '@dotes contains "Alerta"', true);
$case('listas', '@clase in ["Mago", "Bardo", "Brujo"]', true);
$case('listas', '@clase in ["Guerrero", "Pícaro"]', false);
$case('listas', '"lert" in "Alerta"', true);       // subcadena
$case('listas', 'sum([1, 2, 3])', 6);

// Aritmética con difusión: una operación sobre listas va elemento a elemento.
$case('listas', '[1, 2] + [10, 20]', [11, 22]);
$case('listas', '[3, 2] * 2', [6, 4]);
$case('listas', '10 - [1, 2]', [9, 8]);
$case('listas', '[1, 2, 3] * [2]', [2, 0, 0]);      // la corta se completa con null
$case('listas', '-[1, -2]', [-1, 2]);
$case('listas', '[6, 9] / 3', [2, 3]);
$case('listas', '[7, 8] % 3', [1, 2]);
$case('listas', '["a", "b"] + "!"', ['a!', 'b!']);
$case('listas', '@inventario[*].peso * @inventario[*].cantidad', [3, 10]);
$case('listas', 'sum(@sin_dotes * 2)', 0);
$case('listas', '[[1, 2], [3]] * 2', [[2, 4], [6]]);
$fails('listas', '[1, 2] / [1, 0]');
$fails('listas', '[1, 2] / [1]');                   // el hueco vale 0

// ------------------------------------------------- las fórmulas reales de 5e
$case('5e real', '10 + @destreza.mod + @armadura_bonus + if(@escudo, 2, 0)', 17);
$case('5e real', '2 + floor((@nivel - 1) / 4)', 3);
$case('5e real', '8 + @competencia + @carisma.mod', 15);
$case('5e real', '10 + @sabiduria.mod', 11);
$case('5e real', '@destreza.mod + if(@dotes contains "Alerta", 5, 0)', 7);
$case('5e real', 'sum(@inventario[*].peso * @inventario[*].cantidad)', 13);   // §5.4
$case('5e real', '@constitucion.mod + if(@nivel >= 5, @competencia, 0)', 4);

// ---------------------------------------------------------- casos frontera
$case('frontera', '0', 0);
$case('frontera', '-0', 0);
$case('frontera', '""', '');
$case('frontera', 'null', null);
$case('frontera', '@texto_cero', '0');
$case('frontera', '@texto_cero + 1', 1);
$case('frontera', '@texto_cero == 0', true);
$case('frontera', '!@texto_cero', false);          // "0" truthy
$case('frontera', '@cero == false', true);
$case('frontera', '1000000 * 1000000', 1000000000000);
$fails('frontera', '1 +');
$fails('frontera', '(1 + 2');
$fails('frontera', '@');
$fails('frontera', 'fuerza');                       // sin @ no es nada
$fails('frontera', '1 $ 2');
$fails('frontera', '"sin cerrar');
$fails('frontera', str_repeat('(', 80).'1'.str_repeat(')', 80));   // bomba de anidamiento
$fails('frontera', 'max('.str_repeat('max(', 80).'1'.str_repeat(')', 81));


// -------------------------------- casos adversarios entre PHP y JS
// Cada uno de estos es un sitio donde los dos lenguajes se comportan distinto
// por defecto. Si alguno falla, los motores han divergido.
$case('divergencia', 'concat("", 10 / 3)', '3.3333333333');
$case('divergencia', 'concat("", 1 / 3)', '0.3333333333');
$case('divergencia', 'concat("", 2.5)', '2.5');
$case('divergencia', 'concat("", -0.0)', '0');
$case('divergencia', '7 % -3', 1);
$case('divergencia', '-7 % -3', -1);
$case('divergencia', 'round(0.5)', 1);
$case('divergencia', 'round(-0.5)', -1);
$case('divergencia', 'round(1.005, 2)', 1.0);
$case('divergencia', 'floor(-0.0)', 0);
$case('divergencia', 'len("ñandú")', 5);
$case('divergencia', 'len("🎲🎲")', 2);
$case('divergencia', 'upper("ñandú")', 'ÑANDÚ');
$case('divergencia', 'lower("ÁÉÍÓÚ")', 'áéíóú');
$case('divergencia', '"á" > "a"', true);
$case('divergencia', '"z" < "á"', true);
$case('divergencia', '"10" == "1e1"', true);
// "0x10" no es numérico para PHP; JS lo leería como 16 si usara Number().
// El motor de JS replica is_numeric() a propósito, así que aquí concatena.
$case('divergencia', '"0x10" + 0', '0x100');
$case('divergencia', '"0b101" + 0', '0b1010');
$case('divergencia', '"Infinity" + 0', 'Infinity0');
$case('divergencia', '"1e1" + 0', 10);
$case('divergencia', '"  7  " + 1', 8);
$case('divergencia', '" " == 0', false);
$case('divergencia', 'sum([1, "2", true, null])', 4);
$case('divergencia', 'avg([])', 0);
// Por encima de 2^53 un doble ya no distingue enteros consecutivos. PHP haría
// aritmética entera exacta y daría …93; se fuerza float para que coincida.
$case('divergencia', 'pow(2, 53)', 9007199254740992);
$case('divergencia', 'pow(2, 53) + 1', 9007199254740992);

// ------------------------------------------------------------------ compilar

$out = [];
$skipped = 0;

foreach ($cases as $c) {
    $isErrorCase = ! empty($c['error']);

    try {
        $ast = Formula::compile($c['formula']);
    } catch (Throwable $e) {
        if ($isErrorCase) {
            // Error de sintaxis: no hay AST que dar al motor de JS, así que el
            // caso solo se ejecuta del lado de PHP.
            $out[] = [
                'group' => $c['group'],
                'formula' => $c['formula'],
                'phase' => 'compile',
                'error' => true,
            ];

            continue;
        }

        fwrite(STDERR, "!! no compila un caso que debería: {$c['formula']} — {$e->getMessage()}\n");
        $skipped++;

        continue;
    }

    $entry = [
        'group' => $c['group'],
        'formula' => $c['formula'],
        'phase' => 'evaluate',
        'ast' => $ast,
    ];

    if ($isErrorCase) {
        $entry['error'] = true;
    } else {
        $entry['expect'] = $c['expect'];

        // Comprobación de cordura: el valor esperado se declara a mano, pero se
        // verifica contra el motor de PHP al generar. Si no coinciden, el caso
        // está mal escrito y hay que enterarse AHORA, no al ejecutar la batería.
        try {
            $actual = Formula::run($ast, $VALUES, $COMPUTED, $SETTINGS);

            if (json_encode($actual) !== json_encode($c['expect'])) {
                fwrite(STDERR, sprintf(
                    "!! caso mal escrito: %s → esperado %s, PHP da %s\n",
                    $c['formula'],
                    json_encode($c['expect'], JSON_UNESCAPED_UNICODE),
                    json_encode($actual, JSON_UNESCAPED_UNICODE),
                ));
                $skipped++;
            }
        } catch (FormulaRuntimeException $e) {
            fwrite(STDERR, "!! caso que debería evaluar, lanza: {$c['formula']} — {$e->getMessage()}\n");
            $skipped++;
        }
    }

    $out[] = $entry;
}

$payload = [
    'generated_by' => 'tests/fixtures/build-formula-cases.php',
    'values' => $VALUES,
    'computed' => $COMPUTED,
    'settings' => $SETTINGS,
    'cases' => $out,
];

file_put_contents(
    __DIR__.'/formula-cases.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
);

printf("formula-cases.json: %d casos%s\n", count($out), $skipped ? " ({$skipped} PROBLEMAS)" : '');

exit($skipped === 0 ? 0 : 1);
