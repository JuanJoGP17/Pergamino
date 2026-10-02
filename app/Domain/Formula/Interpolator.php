<?php

namespace App\Domain\Formula;

/**
 * Plantillas de tirada: `1d20 + {@destreza.mod} + {@competencia}` → `1d20 + 2 + 3`.
 *
 * No es el motor de fórmulas: es texto con huecos. Cada hueco `{…}` se compila
 * y se evalúa por separado, y el resultado se pega en su sitio. Lo que sale es
 * una expresión de dados lista para el motor de la Fase 6.
 *
 * Los huecos se compilan al publicar, igual que las fórmulas, y el AST de cada
 * uno viaja dentro de compiled_schema.
 */
final class Interpolator
{
    private const HOLE = '/\{([^{}]*)\}/';

    /**
     * Compila los huecos de una plantilla de tirada.
     *
     * @return array{template:string,holes:array<int,array>}|null
     */
    public static function compile(?string $template): ?array
    {
        if ($template === null || trim($template) === '') {
            return null;
        }

        $holes = [];

        // Cada hueco se sustituye por un marcador posicional \0, \1… para no
        // tener que volver a buscar llaves al evaluar.
        $normalized = preg_replace_callback(self::HOLE, function (array $m) use (&$holes) {
            $holes[] = Parser::parse($m[1]);

            return "\0".(count($holes) - 1)."\0";
        }, $template);

        return ['template' => $normalized, 'holes' => $holes];
    }

    /**
     * Rellena los huecos ya compilados.
     *
     * @param  array{template:string,holes:array<int,array>}|null  $compiled
     */
    public static function run(?array $compiled, array $values, array $computed = [], array $settings = []): ?string
    {
        if ($compiled === null) {
            return null;
        }

        $evaluator = Evaluator::make($values, $computed, $settings);

        return preg_replace_callback('/\x00(\d+)\x00/', function (array $m) use ($compiled, $evaluator) {
            $ast = $compiled['holes'][(int) $m[1]] ?? null;

            if ($ast === null) {
                return '';
            }

            try {
                $value = $evaluator->evaluate($ast);
            } catch (FormulaRuntimeException) {
                return '0';
            }

            $n = Value::toNumber($value);

            // Un modificador positivo se pega con su signo para que la
            // expresión resultante sea válida: "1d20 + +3" no lo sería, pero
            // aquí el "+" ya viene en la plantilla, así que solo va el número.
            return Value::toString($n);
        }, $compiled['template']);
    }

    /** @return array<int,string> */
    public static function references(?array $compiled): array
    {
        if ($compiled === null) {
            return [];
        }

        $out = [];
        foreach ($compiled['holes'] as $ast) {
            foreach (Ast::references($ast) as $ref) {
                $out[$ref] = true;
            }
        }

        return array_keys($out);
    }
}
