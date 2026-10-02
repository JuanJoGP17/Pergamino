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
     * El resultado es una lista de trozos: texto literal (cadena) o el índice
     * de un hueco (entero).
     *
     *   "1d20 + {@destreza.mod}" → parts: ["1d20 + ", 0], holes: [AST]
     *
     * No se usan marcadores dentro del texto: PostgreSQL rechaza el carácter
     * NUL en jsonb, y cualquier otro marcador podría aparecer en el texto del
     * usuario. Con trozos separados no hay nada que escapar.
     *
     * @return array{parts:array<int,string|int>,holes:array<int,array>}|null
     */
    public static function compile(?string $template): ?array
    {
        if ($template === null || trim($template) === '') {
            return null;
        }

        // Con DELIM_CAPTURE, los índices pares son texto y los impares el
        // contenido de cada hueco.
        $pieces = preg_split(self::HOLE, $template, -1, PREG_SPLIT_DELIM_CAPTURE);

        $parts = [];
        $holes = [];

        foreach ($pieces as $i => $piece) {
            if ($i % 2 === 0) {
                if ($piece !== '') {
                    $parts[] = $piece;
                }

                continue;
            }

            $holes[] = Parser::parse($piece);
            $parts[] = count($holes) - 1;
        }

        return ['parts' => $parts, 'holes' => $holes];
    }

    /**
     * Rellena los huecos ya compilados.
     *
     * Gemelo: interpolate() en resources/js/formula/evaluate.js.
     *
     * @param  array{parts:array<int,string|int>,holes:array<int,array>}|null  $compiled
     */
    public static function run(?array $compiled, array $values, array $computed = [], array $settings = []): ?string
    {
        if ($compiled === null) {
            return null;
        }

        $evaluator = Evaluator::make($values, $computed, $settings);
        $out = '';

        foreach ($compiled['parts'] ?? [] as $part) {
            if (is_string($part)) {
                $out .= $part;

                continue;
            }

            $ast = $compiled['holes'][$part] ?? null;

            if ($ast === null) {
                continue;
            }

            try {
                // Solo el número: el signo «+» ya viene en la plantilla, y
                // pegar "+3" daría "1d20 + +3".
                $out .= Value::toString(Value::toNumber($evaluator->evaluate($ast)));
            } catch (FormulaRuntimeException) {
                $out .= '0';
            }
        }

        return $out;
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
