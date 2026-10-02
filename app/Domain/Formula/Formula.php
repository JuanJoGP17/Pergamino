<?php

namespace App\Domain\Formula;

/**
 * Fachada del motor. Es lo único que el resto de la aplicación necesita usar.
 *
 *   Formula::compile('10 + @destreza.mod')   → AST (array serializable)
 *   Formula::run($ast, $values, $computed)   → valor
 *   Formula::references($ast)                → ['destreza']
 */
final class Formula
{
    /** Texto → AST. Se llama UNA vez, al publicar una versión de plantilla. */
    public static function compile(?string $source): ?array
    {
        if ($source === null || trim($source) === '') {
            return null;
        }

        return Parser::parse($source);
    }

    /** Compila devolviendo null en vez de lanzar; para validar sin romper. */
    public static function tryCompile(?string $source, ?string &$error = null): ?array
    {
        try {
            $error = null;

            return self::compile($source);
        } catch (FormulaSyntaxException $e) {
            $error = $e->getMessage();

            return null;
        }
    }

    /**
     * Evalúa un AST ya compilado.
     *
     * @param  array<string,mixed>  $values  lo que escribió el usuario
     * @param  array<string,mixed>  $computed  derivados ya resueltos
     * @param  array<string,mixed>  $settings  ajustes de plantilla para mod/prof/lookup
     */
    public static function run(?array $ast, array $values, array $computed = [], array $settings = []): mixed
    {
        if ($ast === null) {
            return null;
        }

        return Evaluator::make($values, $computed, $settings)->evaluate($ast);
    }

    /** Atajo para casos sueltos: compila y evalúa de una vez. */
    public static function evaluate(string $source, array $values = [], array $computed = [], array $settings = []): mixed
    {
        return self::run(self::compile($source), $values, $computed, $settings);
    }

    /** @return array<int,string> claves de campo referenciadas por el árbol */
    public static function references(?array $ast): array
    {
        return Ast::references($ast);
    }

    /**
     * Comprueba una fórmula sin ejecutarla: sintaxis, funciones conocidas y
     * aridad correcta. Es lo que usa el panel de avisos del constructor.
     *
     * @return array<int,string> lista de problemas; vacía si todo está bien
     */
    public static function lint(?string $source, array $knownKeys = []): array
    {
        $ast = self::tryCompile($source, $error);

        if ($error !== null) {
            return [$error];
        }

        if ($ast === null) {
            return [];
        }

        $problems = [];

        Ast::walk($ast, function (array $node) use (&$problems, $knownKeys) {
            if ($node['n'] === 'call') {
                if (! FunctionRegistry::exists($node['fn'])) {
                    $problems[] = "la función «{$node['fn']}» no existe";

                    return;
                }

                try {
                    FunctionRegistry::checkArity($node['fn'], count($node['args']));
                } catch (FormulaRuntimeException $e) {
                    $problems[] = $e->getMessage();
                }
            }

            if ($node['n'] === 'ref' && $knownKeys !== []) {
                // @self es un alias del lenguaje; el campo real es el primer
                // tramo del camino.
                $key = $node['key'] === Ast::SELF
                    ? ($node['path'][0] ?? null)
                    : $node['key'];

                if ($key !== null && ! in_array($key, $knownKeys, true)) {
                    $problems[] = "el campo «@{$key}» no existe en esta plantilla";
                }
            }
        });

        return array_values(array_unique($problems));
    }
}
