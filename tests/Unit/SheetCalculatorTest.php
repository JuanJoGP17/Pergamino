<?php

use App\Domain\Sheet\SheetCalculator;

it('calcula el modificador d20 estándar', function (int $score, int $expected) {
    expect((new SheetCalculator)->attributeModifier($score))->toBe($expected);
})->with([
    [1, -5], [7, -2], [8, -1], [10, 0], [11, 0], [16, 3], [20, 5], [30, 10],
]);

it('redondea hacia abajo también con puntuaciones bajas', function () {
    // floor(-3/2) = -2, no -1: el error clásico de usar división entera.
    expect((new SheetCalculator)->attributeModifier(7))->toBe(-2);
});

it('respeta la base y el divisor de la plantilla', function () {
    expect((new SheetCalculator)->attributeModifier(12, ['mod_base' => 0, 'mod_divisor' => 3]))
        ->toBe(4);
});

it('no divide por cero', function () {
    expect((new SheetCalculator)->attributeModifier(12, ['mod_divisor' => 0]))->toBe(0);
});

it('formatea el modificador con signo', function () {
    expect(SheetCalculator::formatModifier(3))->toBe('+3')
        ->and(SheetCalculator::formatModifier(-1))->toBe('-1')
        ->and(SheetCalculator::formatModifier(0))->toBe('+0');
});
