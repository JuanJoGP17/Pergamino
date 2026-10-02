<?php

namespace App\Domain\Schema;

use RuntimeException;

class SchemaCompilationException extends RuntimeException
{
    /** @var array<int,array> */
    public array $issues = [];

    /** @param array<int,array> $issues */
    public static function withIssues(array $issues): self
    {
        $first = $issues[0]['message'] ?? 'La plantilla tiene errores.';
        $more = count($issues) - 1;

        $e = new self($more > 0 ? "{$first} (y {$more} error(es) más)" : $first);
        $e->issues = $issues;

        return $e;
    }

    /** @param array<int,string> $keys campos implicados en el ciclo */
    public static function cycle(array $keys): self
    {
        $chain = implode(' → ', $keys);

        $e = new self("Las fórmulas forman un ciclo entre: {$chain}. Un campo no puede depender de sí mismo, ni directa ni indirectamente.");
        $e->issues = [[
            'level' => 'error',
            'message' => $e->getMessage(),
            'field' => $keys[0] ?? null,
        ]];

        return $e;
    }
}
