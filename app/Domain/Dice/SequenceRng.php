<?php

namespace App\Domain\Dice;

use LogicException;

/**
 * Azar con guion, para los tests: devuelve los valores dados en orden.
 *
 *   new SequenceRng([6, 3, 1])  → el primer dado saca 6, el segundo 3…
 *
 * Un valor fuera del rango del dado es un error del test, no del motor.
 */
final class SequenceRng extends Rng
{
    /** @param array<int,int> $values */
    public function __construct(private array $values) {}

    public function int(int $min, int $max): int
    {
        if ($this->values === []) {
            throw new LogicException('SequenceRng: se han acabado los valores.');
        }

        $v = array_shift($this->values);

        if ($v < $min || $v > $max) {
            throw new LogicException("SequenceRng: {$v} no cabe en {$min}..{$max}.");
        }

        return $v;
    }
}
