<?php

use App\Domain\Formula\Ast;
use App\Domain\Formula\Formula;
use App\Domain\Formula\FormulaRuntimeException;
use App\Domain\Formula\FormulaSyntaxException;
use App\Domain\Formula\Interpolator;

/**
 * La batería exhaustiva vive en tests/fixtures/formula-cases.json y se ejecuta
 * contra los DOS motores (ver FormulaParityTest). Aquí quedan los casos que
 * describen decisiones de diseño y conviene leer como documentación.
 */
$ctx = fn () => [
    ['nivel' => 5, 'fuerza' => 16, 'clase' => 'Bardo', 'dotes' => ['Alerta']],
    ['fuerza' => ['mod' => 3], 'competencia' => 3],
];

it('evalúa las fórmulas reales de una hoja de 5e', function () use ($ctx) {
    [$values, $computed] = $ctx();

    expect(Formula::evaluate('8 + @competencia + @fuerza.mod', $values, $computed))->toBe(14)
        ->and(Formula::evaluate('2 + floor((@nivel - 1) / 4)', $values, $computed))->toBe(3)
        ->and(Formula::evaluate('@fuerza.mod + if(@dotes contains "Alerta", 5, 0)', $values, $computed))->toBe(8);
});

it('trata @self como alias del propio contexto', function () use ($ctx) {
    [$values, $computed] = $ctx();

    expect(Formula::evaluate('@self.nivel', $values, $computed))->toBe(5)
        ->and(Formula::references(Formula::compile('@self.nivel')))->toBe(['nivel'])
        ->and(Formula::references(Formula::compile('@self.nivel')))->not->toContain('self');
});

it('saca las referencias del árbol y no de una expresión regular', function () {
    // Una arroba dentro de una cadena no es una referencia.
    expect(Formula::references(Formula::compile('if(@clase == "mago@torre", 1, 0)')))
        ->toBe(['clase']);
});

it('evalúa `if` de forma perezosa', function () {
    // Si evaluara las dos ramas, esto reventaría por división por cero.
    expect(Formula::evaluate('if(@d == 0, 0, 100 / @d)', ['d' => 0]))->toBe(0);
});

it('cortocircuita && y ||', function () {
    expect(Formula::evaluate('@d != 0 && 10 / @d > 1', ['d' => 0]))->toBeFalse()
        ->and(Formula::evaluate('@d == 0 || 10 / @d > 1', ['d' => 0]))->toBeTrue();
});

it('lanza error en vez de devolver cero al dividir por cero', function () {
    // Un cero silencioso sería un número equivocado en la hoja durante la
    // partida; un error permite mostrar «—» y explicar por qué.
    expect(fn () => Formula::evaluate('10 / 0', []))
        ->toThrow(FormulaRuntimeException::class);
});

it('rechaza sintaxis inválida señalando la posición', function () {
    expect(fn () => Formula::compile('1 +'))->toThrow(FormulaSyntaxException::class)
        ->and(fn () => Formula::compile('(1 + 2'))->toThrow(FormulaSyntaxException::class)
        ->and(fn () => Formula::compile('fuerza'))->toThrow(FormulaSyntaxException::class);
});

it('sugiere la arroba cuando se escribe un campo sin ella', function () {
    try {
        Formula::compile('fuerza + 1');
    } catch (FormulaSyntaxException $e) {
        expect($e->getMessage())->toContain('@fuerza');
    }
});

it('no permite llamar a funciones fuera de la lista blanca', function () {
    expect(fn () => Formula::evaluate('system("ls")', []))
        ->toThrow(FormulaRuntimeException::class);
});

it('acota la profundidad y el tamaño de una fórmula', function () {
    $bomba = str_repeat('(', 200).'1'.str_repeat(')', 200);

    expect(fn () => Formula::compile($bomba))->toThrow(FormulaSyntaxException::class);
});

it('detecta problemas sin ejecutar la fórmula', function () {
    expect(Formula::lint('floor(1, 2)'))->toHaveCount(1)
        ->and(Formula::lint('@no_existe + 1', ['fuerza']))->toHaveCount(1)
        ->and(Formula::lint('@fuerza + 1', ['fuerza']))->toBeEmpty()
        ->and(Formula::lint(null))->toBeEmpty();
});

it('interpola las plantillas de tirada', function () use ($ctx) {
    [$values, $computed] = $ctx();
    $compiled = Interpolator::compile('1d20 + {@fuerza.mod} + {@competencia}');

    expect(Interpolator::run($compiled, $values, $computed))->toBe('1d20 + 3 + 3')
        ->and(Interpolator::references($compiled))->toBe(['fuerza', 'competencia']);
});

it('produce un AST serializable a JSON y de vuelta', function () {
    $ast = Formula::compile('10 + @destreza.mod');
    $roundTrip = json_decode(json_encode($ast), true);

    // Es la propiedad de la que depende todo el diseño: el AST viaja al
    // navegador dentro de compiled_schema y tiene que sobrevivir intacto.
    expect($roundTrip)->toBe($ast)
        ->and(Formula::run($roundTrip, [], ['destreza' => ['mod' => 4]]))->toBe(14);
});

it('mantiene el AST dentro de los límites declarados', function () {
    $ast = Formula::compile('8 + prof(@nivel) + @carisma.mod');

    expect(Ast::depth($ast))->toBeLessThan(Ast::MAX_DEPTH)
        ->and(Ast::count($ast))->toBeLessThan(Ast::MAX_NODES);
});
