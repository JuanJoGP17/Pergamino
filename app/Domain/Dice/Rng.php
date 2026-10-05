<?php

namespace App\Domain\Dice;

/**
 * Fuente de azar del motor de dados. En producción, random_int() (CSPRNG,
 * §7: «nunca rand()»). Los tests usan SequenceRng para saber qué sale.
 */
class Rng
{
    public function int(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
