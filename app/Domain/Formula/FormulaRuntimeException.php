<?php

namespace App\Domain\Formula;

use RuntimeException;

/**
 * Error al EVALUAR una fórmula sintácticamente correcta: división por cero,
 * función con argumentos imposibles…
 *
 * SheetCalculator la captura campo a campo: una fórmula rota deja ese campo en
 * «—» con su aviso, pero no tumba el resto de la hoja.
 */
class FormulaRuntimeException extends RuntimeException
{
    public ?string $fieldKey = null;

    public static function divisionByZero(): self
    {
        return new self('división por cero');
    }

    public static function unknownFunction(string $name): self
    {
        return new self("la función «{$name}» no existe");
    }

    public static function badArity(string $name, string $expected, int $got): self
    {
        return new self("la función «{$name}» espera {$expected} argumento(s), recibió {$got}");
    }

    public function forField(string $key): self
    {
        $this->fieldKey = $key;

        return $this;
    }
}
