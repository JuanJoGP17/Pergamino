<?php

namespace App\Domain\Formula;

/**
 * Coerciones del lenguaje de fórmulas.
 *
 * ⚠ ESTE ES EL ARCHIVO CRÍTICO. Su gemelo es `resources/js/formula/value.js`.
 * Cualquier cambio aquí tiene que replicarse allí y quedar cubierto por
 * `tests/fixtures/formula-cases.json`, que ejecuta los mismos casos contra los
 * dos motores. Si divergen, la hoja mostraría un número mientras escribes y
 * otro distinto al guardar — el peor tipo de bug posible en este proyecto.
 *
 * Reglas, elegidas para ser predecibles antes que "idiomáticas" de PHP o de JS:
 *
 *   - Un solo tipo numérico. Se opera en coma flotante y se devuelve entero si
 *     el resultado lo es, para que ambos motores serialicen el mismo JSON.
 *   - null → 0 en aritmética, "" en texto.
 *   - false → 0, true → 1.
 *   - Cadena numérica → su número; cadena no numérica → 0.
 *   - Falsy: null, false, 0, "", [].
 *     Nótese que "0" es TRUTHY: PHP nativo diría lo contrario, pero se ha
 *     unificado con la regla de JS por ser la menos sorprendente de las dos y,
 *     sobre todo, porque tenía que elegirse UNA.
 */
final class Value
{
    /**
     * Normaliza un número: entero si es integral, float si no.
     *
     * Es lo que mantiene el JSON idéntico entre PHP y JS. Sin esto, PHP
     * serializaría `3.0` donde JS escribe `3`.
     */
    public static function number(int|float $n): int|float
    {
        if (is_int($n)) {
            return $n;
        }

        if (is_nan($n) || is_infinite($n)) {
            return $n;
        }

        // Fuera del entero seguro (2^53) se queda como float: por encima de
        // ahí un doble ya no representa todos los enteros, y forzar la
        // conversión daría formatos distintos en cada lenguaje.
        if ($n == (int) $n && abs($n) <= 9007199254740992) {
            return (int) $n;
        }

        return $n;
    }

    /**
     * Cualquier valor → número.
     *
     * Devuelve SIEMPRE float, nunca int. Es deliberado: PHP hace aritmética
     * entera exacta de 64 bits y JavaScript solo tiene dobles, así que a partir
     * de 2^53 los dos motores darían resultados distintos. Operando siempre en
     * coma flotante, ambos pierden precisión en el mismo sitio y de la misma
     * forma. La conversión a entero se hace solo al PRESENTAR, en number().
     */
    public static function toNumber(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        if ($v === null || $v === false) {
            return 0.0;
        }

        if ($v === true) {
            return 1.0;
        }

        if (is_string($v)) {
            $t = trim($v);

            return is_numeric($t) ? (float) $t : 0.0;
        }

        return 0.0;
    }

    public static function toString(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }

        if ($v === null) {
            return '';
        }

        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        if (is_int($v)) {
            return (string) $v;
        }

        if (is_float($v)) {
            $n = self::number($v);

            // Formato sin notación científica ni ceros de relleno, igual que JS.
            return is_int($n) ? (string) $n : rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
        }

        if (is_array($v)) {
            return implode(',', array_map([self::class, 'toString'], $v));
        }

        return '';
    }

    public static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        if ($v === null) {
            return false;
        }

        if (is_int($v) || is_float($v)) {
            return $v != 0;
        }

        if (is_string($v)) {
            return $v !== '';       // ojo: "0" es truthy, ver cabecera
        }

        if (is_array($v)) {
            return $v !== [];
        }

        return true;
    }

    /** @return array<int,mixed> */
    public static function toArray(mixed $v): array
    {
        if (is_array($v)) {
            return array_values($v);
        }

        if ($v === null) {
            return [];
        }

        return [$v];
    }

    /**
     * Igualdad laxa. Dos números se comparan como números; si alguno es cadena
     * no numérica, se comparan como texto.
     */
    public static function looseEquals(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return self::toArray($a) == self::toArray($b);
        }

        if ($a === null && $b === null) {
            return true;
        }

        $aNumeric = self::isNumericish($a);
        $bNumeric = self::isNumericish($b);

        if ($aNumeric && $bNumeric) {
            return self::toNumber($a) == self::toNumber($b);
        }

        if (is_bool($a) || is_bool($b)) {
            return self::toBool($a) === self::toBool($b);
        }

        return self::toString($a) === self::toString($b);
    }

    /** Comparación de orden: numérica si ambos lo permiten, si no alfabética. */
    public static function compare(mixed $a, mixed $b): int
    {
        if (self::isNumericish($a) && self::isNumericish($b)) {
            $x = self::toNumber($a);
            $y = self::toNumber($b);

            return $x <=> $y;
        }

        return strcmp(self::toString($a), self::toString($b));
    }

    private static function isNumericish(mixed $v): bool
    {
        if (is_int($v) || is_float($v) || is_bool($v) || $v === null) {
            return true;
        }

        return is_string($v) && is_numeric(trim($v)) && trim($v) !== '';
    }

    /** ¿Contiene la aguja? Sirve para arrays y para subcadenas. */
    public static function contains(mixed $haystack, mixed $needle): bool
    {
        if (is_array($haystack)) {
            foreach ($haystack as $item) {
                if (self::looseEquals($item, $needle)) {
                    return true;
                }
            }

            return false;
        }

        if ($haystack === null) {
            return false;
        }

        $s = self::toString($haystack);
        $n = self::toString($needle);

        return $n !== '' && str_contains($s, $n);
    }
}
