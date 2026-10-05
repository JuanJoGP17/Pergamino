<?php

namespace App\Domain\Dice;

use RuntimeException;

/** Una tirada que no se puede hacer; el mensaje es para quien la escribió. */
final class DiceException extends RuntimeException
{
    public static function at(string $message, int $position): self
    {
        return new self("{$message} (posición ".($position + 1).')');
    }
}
