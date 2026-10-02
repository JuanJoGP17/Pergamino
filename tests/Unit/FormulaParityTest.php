<?php

use App\Domain\Formula\Evaluator;
use App\Domain\Formula\Formula;
use App\Domain\Formula\FormulaRuntimeException;
use App\Domain\Formula\FormulaSyntaxException;

/**
 * La batería compartida entre PHP y JavaScript.
 *
 * Este test cubre el lado de PHP. El de JavaScript se ejecuta con
 * `node tests/fixtures/run-js.mjs`, y `bash tests/fixtures/diff-engines.sh`
 * compara las dos salidas entre sí.
 *
 * Es la única defensa real contra el riesgo principal del proyecto: que los dos
 * motores se separen sin que nadie se dé cuenta (§16 del plan).
 */
$fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/formula-cases.json'), true);

it('tiene una batería compartida cargada', function () use ($fixture) {
    expect($fixture)->toHaveKeys(['values', 'computed', 'settings', 'cases'])
        ->and($fixture['cases'])->not->toBeEmpty();
});

/**
 * Dataset con nombre legible: `"[grupo] fórmula" => [caso]`.
 *
 * El caso va envuelto en su propio array. Sin ese envoltorio, Pest toma las
 * claves de texto del caso como parámetros con nombre y revienta.
 */
function datasetOf(array $cases): array
{
    $out = [];
    foreach ($cases as $i => $c) {
        $out["#{$i} [{$c['group']}] {$c['formula']}"] = [$c];
    }

    return $out;
}

$evaluate = array_values(array_filter($fixture['cases'], fn ($c) => ($c['phase'] ?? '') === 'evaluate'));
$compile = array_values(array_filter($fixture['cases'], fn ($c) => ($c['phase'] ?? '') === 'compile'));

it('evalúa igual que la batería compartida', function (array $case) use ($fixture) {
    $evaluator = Evaluator::make($fixture['values'], $fixture['computed'], $fixture['settings']);

    if (! empty($case['error'])) {
        expect(fn () => $evaluator->evaluate($case['ast']))->toThrow(FormulaRuntimeException::class);

        return;
    }

    expect(json_encode($evaluator->evaluate($case['ast'])))
        ->toBe(json_encode($case['expect']));
})->with(datasetOf($evaluate));

it('rechaza al analizar lo que debe rechazar', function (array $case) {
    expect(fn () => Formula::compile($case['formula']))->toThrow(FormulaSyntaxException::class);
})->with(datasetOf($compile));
