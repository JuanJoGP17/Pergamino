<?php

use App\Domain\Dice\DiceException;
use App\Domain\Dice\DiceRoller;
use App\Domain\Dice\SequenceRng;

/**
 * Motor de dados (§7). Con SequenceRng se sabe qué sale en cada dado, así que
 * se puede comprobar cada regla de la notación con números exactos.
 */
function rollWith(array $values, string $expression, string $mode = 'normal'): array
{
    return (new DiceRoller(new SequenceRng($values)))->roll($expression, $mode);
}

function values(array $result, int $group = 0): array
{
    return array_column($result['groups'][$group]['dice'], 'value');
}

function kept(array $result, int $group = 0): array
{
    return array_column($result['groups'][$group]['dice'], 'kept');
}

it('suma dados y modificadores', function () {
    $r = rollWith([14], '1d20 + 5');

    expect($r['total'])->toBe(19)
        ->and($r['groups'][0])->toMatchArray(['notation' => '1d20', 'sides' => 20, 'value' => 14])
        ->and($r['crit'])->toBeFalse()
        ->and($r['successes'])->toBeNull();
});

it('quedarse con los más altos o bajos, y descartar', function () {
    expect(rollWith([5, 3, 6, 1], '4d6kh3')['total'])->toBe(14)
        ->and(kept(rollWith([5, 3, 6, 1], '4d6kh3')))->toBe([true, true, true, false])
        ->and(rollWith([5, 3, 6, 1], '4d6k3')['total'])->toBe(14)
        ->and(rollWith([5, 3, 6, 1], '4d6dl1')['total'])->toBe(14)
        ->and(rollWith([5, 3, 6, 1], '4d6dh1')['total'])->toBe(9)
        ->and(rollWith([15, 4], '2d20kl1')['total'])->toBe(4)
        // Empate: se descarta el primero de los iguales, siempre el mismo.
        ->and(kept(rollWith([3, 3, 5], '3d6kh2')))->toBe([false, true, true]);
});

it('dados explosivos: el máximo, o lo que cumpla la comparación, encadenados', function () {
    $r = rollWith([6, 6, 2, 4, 1], '3d6!');

    expect($r['total'])->toBe(19)
        ->and(values($r))->toBe([6, 6, 2, 4, 1])
        ->and(array_map(fn ($d) => $d['exploded'] ?? false, $r['groups'][0]['dice']))->toBe([false, true, true, false, false])
        ->and(rollWith([5, 6, 3], '1d6!>=5')['total'])->toBe(14)
        ->and(rollWith([5, 2], '1d6!5')['total'])->toBe(7);
});

it('relanzar: mientras salga (r) o una sola vez (ro)', function () {
    $r = rollWith([1, 1, 4, 3], '2d6r1');

    expect($r['total'])->toBe(7)
        ->and($r['groups'][0]['dice'][0])->toBe(['value' => 4, 'kept' => true, 'rerolled' => [1, 1]])
        ->and(rollWith([1, 1, 3], '2d6ro1')['total'])->toBe(4)
        ->and(rollWith([2, 1, 5], '1d20r<3')['total'])->toBe(5);
});

it('cuenta éxitos al estilo Storyteller', function () {
    $r = rollWith([7, 2, 10, 6, 8], '5d10>=7');

    expect($r['total'])->toBe(3)
        ->and($r['successes'])->toBe(3)
        ->and(array_column($r['groups'][0]['dice'], 'success'))->toBe([true, false, true, false, true])
        ->and(rollWith([3, 4, 1], '3d6>3')['successes'])->toBe(1);
});

it('dados Fudge de FATE', function () {
    $r = rollWith([-1, 0, 1, 1], '4dF + 2');

    expect($r['total'])->toBe(3)->and($r['groups'][0]['sides'])->toBe('F');
});

it('crítico y pifia: 20 y 1 naturales en un d20, o el umbral que se diga', function () {
    expect(rollWith([20], '1d20+3')['crit'])->toBeTrue()
        ->and(rollWith([1], '1d20+3')['fumble'])->toBeTrue()
        ->and(rollWith([19], '1d20+3')['crit'])->toBeFalse()
        ->and(rollWith([19], '1d20cs19')['crit'])->toBeTrue()
        ->and(rollWith([3], '1d20cf3')['fumble'])->toBeTrue()
        ->and(rollWith([6], '1d6')['crit'])->toBeFalse()
        // El crítico se mira en el dado que se queda: con ventaja, el 1 descartado no es pifia.
        ->and(rollWith([1, 12], '1d20', 'advantage')['fumble'])->toBeFalse();
});

it('ventaja y desventaja convierten el primer 1d20 en 2d20kh1 / kl1', function () {
    $adv = rollWith([5, 17], '1d20+3', 'advantage');
    $dis = rollWith([5, 17], 'd20+3', 'disadvantage');

    expect($adv['total'])->toBe(20)
        ->and($adv['groups'][0]['notation'])->toBe('2d20kh1')
        ->and($dis['total'])->toBe(8)
        ->and($dis['groups'][0]['notation'])->toBe('2d20kl1')
        // Sin d20 que convertir, la tirada no cambia.
        ->and(rollWith([4], '1d6', 'advantage')['total'])->toBe(4);
});

it('aritmética con paréntesis, división hacia abajo y negativos', function () {
    expect(rollWith([2, 3], '(2d6+3)*2')['total'])->toBe(16)
        ->and(rollWith([7], '1d8/2')['total'])->toBe(3)
        ->and(rollWith([], '-7/2')['total'])->toBe(-4)
        ->and(rollWith([42], 'd%')['total'])->toBe(42)
        ->and(rollWith([3, 4], '1D6 + 1d6 - 2')['total'])->toBe(5);
});

it('rechaza lo que no es una tirada, con un mensaje que se entiende', function (string $expression, string $message) {
    expect(fn () => rollWith([1, 1, 1, 1, 1, 1], $expression))->toThrow(DiceException::class, $message);
})->with([
    'vacía' => ['', 'vacía'],
    'a medias' => ['1d20+', 'acaba a medias'],
    'letras' => ['fuego', 'caracteres que no son de dados'],
    'dado de 0 caras' => ['1d0', 'caras'],
    'demasiados dados' => ['101d6', 'de 1 a 100 dados'],
    'relanzar siempre' => ['1d6r<7', 'relanzaría todos'],
    'explotar siempre' => ['1d6!>=1', 'explotaría siempre'],
    'paréntesis abierto' => ['(1d6+2', 'cerrar un paréntesis'],
    'división por cero' => ['1d6/0', 'División por cero'],
    'demasiado larga' => [str_repeat('1+', 101).'1', 'demasiado larga'],
]);

it('no deja que una cadena de explosiones tire miles de dados', function () {
    expect(fn () => rollWith(array_fill(0, 1100, 2), '100d2!'))->toThrow(DiceException::class, 'Demasiados dados');
});

it('con azar de verdad, cada cara sale y ninguna se sale del dado', function () {
    $seen = [];
    $roller = new DiceRoller;

    for ($i = 0; $i < 600; $i++) {
        $seen[] = $roller->roll('1d6')['total'];
    }

    expect(min($seen))->toBe(1)->and(max($seen))->toBe(6)->and(count(array_unique($seen)))->toBe(6);
});
