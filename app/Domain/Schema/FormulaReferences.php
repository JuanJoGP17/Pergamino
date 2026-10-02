<?php

namespace App\Domain\Schema;

use App\Domain\Formula\Ast;
use App\Domain\Formula\Formula;
use App\Domain\Formula\FormulaSyntaxException;
use App\Domain\Formula\Interpolator;

/**
 * Referencias de campo que aparecen en las fórmulas de un campo.
 *
 * FASE 2: esta clase ya NO escanea con expresiones regulares. Analiza de verdad
 * y saca las referencias del AST. La diferencia importa: el escáner de la Fase 1
 * no distinguía `@fuerza` dentro de una cadena de texto de una referencia real,
 * y una fórmula como `if(@clase == "mago@torre", …)` le hacía inventarse una
 * dependencia a un campo «torre» inexistente.
 *
 * La firma es la misma que antes a propósito: SchemaCompiler no se entera del
 * cambio.
 */
final class FormulaReferences
{
    /**
     * @return array<int,string>
     */
    public static function extract(?string $formula): array
    {
        if ($formula === null || trim($formula) === '') {
            return [];
        }

        try {
            return Ast::references(Formula::compile($formula));
        } catch (FormulaSyntaxException) {
            // Una fórmula que no compila no aporta dependencias fiables. El
            // error se reporta desde SchemaValidator, que es quien tiene que
            // hablar de sintaxis; aquí solo interesan las aristas del grafo.
            return [];
        }
    }

    /**
     * Todas las referencias de un campo: fórmula de cálculo, condiciones de
     * visibilidad y de solo lectura, y los huecos de la plantilla de tirada.
     *
     * @param  array<string,mixed>  $field
     * @return array<int,string>
     */
    public static function forField(array $field): array
    {
        $refs = [];

        foreach (['formula', 'visible_if', 'readonly_if'] as $slot) {
            $refs = array_merge($refs, self::extract($field[$slot] ?? null));
        }

        // El modificador de un atributo puede depender de otros campos
        // (`floor((@self - 10) / 2) + @bono_racial`), así que también aporta
        // aristas. `@self` no: es el propio campo, y el grafo ignora los bucles
        // de un nodo consigo mismo.
        $refs = array_merge($refs, self::extract($field['config']['mod_formula'] ?? null));

        // La plantilla de tirada no es una fórmula, es texto con huecos.
        $roll = $field['roll_expression'] ?? null;
        if ($roll !== null && trim((string) $roll) !== '') {
            try {
                $refs = array_merge($refs, Interpolator::references(Interpolator::compile($roll)));
            } catch (FormulaSyntaxException) {
                // Igual que arriba: el aviso lo da el validador.
            }
        }

        return array_values(array_unique($refs));
    }
}
