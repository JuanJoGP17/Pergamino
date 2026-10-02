<?php

namespace App\Domain\Formula;

use RuntimeException;

/**
 * Error al analizar una fórmula. Siempre señala la posición: sin ella, "error
 * de sintaxis" en una expresión de 80 caracteres no ayuda a nadie.
 */
class FormulaSyntaxException extends RuntimeException
{
    public int $position = 0;

    public string $source = '';

    public static function at(string $message, int $position, string $source): self
    {
        $e = new self(sprintf(
            '%s (posición %d) en: %s',
            $message,
            $position,
            self::pointer($source, $position),
        ));

        $e->position = $position;
        $e->source = $source;

        return $e;
    }

    /** Recorta la fórmula alrededor del fallo y marca el punto con «▸». */
    private static function pointer(string $source, int $position): string
    {
        $from = max(0, $position - 20);
        $slice = mb_substr($source, $from, 40);
        $offset = $position - $from;

        return mb_substr($slice, 0, $offset).'▸'.mb_substr($slice, $offset);
    }
}
