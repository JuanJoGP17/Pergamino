<?php

namespace App\Domain\Formula;

/**
 * Lista blanca de funciones (§5.3 del plan).
 *
 * Nada de `eval`, nada de llamar a funciones de PHP por nombre. Solo existe lo
 * que está declarado aquí, y cada entrada fija su aridad para que un error se
 * detecte al compilar y no en mitad de una partida.
 *
 * Gemelo: `resources/js/formula/functions.js`.
 */
final class FunctionRegistry
{
    /**
     * nombre => [aridad mínima, aridad máxima (null = ilimitada)]
     *
     * @var array<string,array{0:int,1:?int}>
     */
    public const ARITY = [
        // Matemáticas
        'floor' => [1, 1], 'ceil' => [1, 1], 'round' => [1, 2], 'abs' => [1, 1],
        'min' => [1, null], 'max' => [1, null], 'clamp' => [3, 3],
        'pow' => [2, 2], 'sqrt' => [1, 1], 'sign' => [1, 1],

        // Agregación sobre listas
        'sum' => [1, null], 'avg' => [1, null], 'count' => [1, 1],
        'any' => [1, 1], 'all' => [1, 1],

        // Lógica
        'if' => [3, 3], 'coalesce' => [1, null], 'switch' => [3, null],

        // Texto
        'concat' => [0, null], 'upper' => [1, 1], 'lower' => [1, 1], 'len' => [1, 1],

        // Rol
        'mod' => [1, 1], 'prof' => [1, 1], 'lookup' => [2, 3], 'tier' => [2, 2],

        // Dados (valor medio, no tira)
        'avg_of' => [1, 1],
    ];

    public static function exists(string $name): bool
    {
        return isset(self::ARITY[$name]);
    }

    /** @return array<int,string> */
    public static function names(): array
    {
        return array_keys(self::ARITY);
    }

    public static function checkArity(string $name, int $given): void
    {
        if (! self::exists($name)) {
            throw FormulaRuntimeException::unknownFunction($name);
        }

        [$min, $max] = self::ARITY[$name];

        if ($given < $min || ($max !== null && $given > $max)) {
            $expected = $max === null ? "al menos {$min}" : ($min === $max ? (string) $min : "entre {$min} y {$max}");
            throw FormulaRuntimeException::badArity($name, $expected, $given);
        }
    }

    /**
     * Ejecuta la función con los argumentos ya evaluados.
     *
     * @param  array<int,mixed>  $args
     * @param  array<string,mixed>  $settings  configuración de la plantilla (mod, prof, tablas)
     */
    public static function call(string $name, array $args, array $settings = []): mixed
    {
        self::checkArity($name, count($args));

        $n = fn (int $i) => Value::toNumber($args[$i] ?? null);
        $s = fn (int $i) => Value::toString($args[$i] ?? null);

        return match ($name) {
            // --- matemáticas -------------------------------------------
            'floor' => Value::number(floor($n(0))),
            'ceil' => Value::number(ceil($n(0))),
            'round' => Value::number(self::roundHalfUp($n(0), isset($args[1]) ? (int) $n(1) : 0)),
            'abs' => Value::number(abs($n(0))),
            'sqrt' => Value::number($n(0) < 0 ? 0 : sqrt($n(0))),
            'sign' => Value::number($n(0) <=> 0),
            'pow' => Value::number($n(0) ** $n(1)),
            'min' => Value::number(min(self::numbers($args))),
            'max' => Value::number(max(self::numbers($args))),
            'clamp' => Value::number(max($n(1), min($n(2), $n(0)))),

            // --- agregación ---------------------------------------------
            'sum' => Value::number(array_sum(self::flatNumbers($args))),
            'avg' => self::average(self::flatNumbers($args)),
            'count' => Value::number(count(Value::toArray($args[0]))),
            'any' => self::anyOf(Value::toArray($args[0])),
            'all' => self::allOf(Value::toArray($args[0])),

            // --- lógica --------------------------------------------------
            'if' => Value::toBool($args[0]) ? $args[1] : $args[2],
            'coalesce' => self::coalesce($args),
            'switch' => self::switchOn($args),

            // --- texto ---------------------------------------------------
            'concat' => implode('', array_map([Value::class, 'toString'], $args)),
            'upper' => mb_strtoupper($s(0)),
            'lower' => mb_strtolower($s(0)),
            'len' => Value::number(is_array($args[0]) ? count($args[0]) : mb_strlen($s(0))),

            // --- rol ------------------------------------------------------
            'mod' => Value::number(self::attributeMod($n(0), $settings)),
            'prof' => Value::number(self::proficiency($n(0), $settings)),
            'lookup' => self::lookup($s(0), $args[1] ?? null, $args[2] ?? null, $settings),
            'tier' => self::tier($n(0), Value::toArray($args[1])),

            // --- dados ----------------------------------------------------
            'avg_of' => Value::number(self::averageRoll($s(0))),

            default => throw FormulaRuntimeException::unknownFunction($name),
        };
    }

    // ------------------------------------------------------------ ayudantes

    /**
     * Redondeo "medio hacia arriba", incluso con negativos: round(-0.5) = -1.
     * PHP ya lo hace así; JS con Math.round daría 0, por eso el gemelo en JS
     * implementa esta regla explícitamente.
     */
    private static function roundHalfUp(float $v, int $precision = 0): float
    {
        $factor = 10 ** $precision;
        $scaled = $v * $factor;

        $rounded = $scaled >= 0
            ? floor($scaled + 0.5)
            : -floor(-$scaled + 0.5);

        return $rounded / $factor;
    }

    /** @return array<int,int|float> */
    private static function numbers(array $args): array
    {
        return array_map([Value::class, 'toNumber'], self::flatten($args));
    }

    /** @return array<int,int|float> */
    private static function flatNumbers(array $args): array
    {
        return self::numbers($args);
    }

    /** sum(@a, @b) y sum(@lista) deben comportarse igual. */
    private static function flatten(array $args): array
    {
        $out = [];

        foreach ($args as $arg) {
            if (is_array($arg)) {
                foreach ($arg as $item) {
                    $out[] = $item;
                }
            } else {
                $out[] = $arg;
            }
        }

        return $out;
    }

    private static function average(array $numbers): int|float
    {
        if ($numbers === []) {
            return 0;
        }

        return Value::number(array_sum($numbers) / count($numbers));
    }

    private static function anyOf(array $items): bool
    {
        foreach ($items as $item) {
            if (Value::toBool($item)) {
                return true;
            }
        }

        return false;
    }

    private static function allOf(array $items): bool
    {
        foreach ($items as $item) {
            if (! Value::toBool($item)) {
                return false;
            }
        }

        return true;
    }

    /** Primer argumento no nulo y no vacío. */
    private static function coalesce(array $args): mixed
    {
        foreach ($args as $arg) {
            if ($arg !== null && $arg !== '') {
                return $arg;
            }
        }

        return null;
    }

    /** switch(valor, caso1, resultado1, …, [porDefecto]) */
    private static function switchOn(array $args): mixed
    {
        $subject = $args[0];
        $rest = array_slice($args, 1);
        $pairs = intdiv(count($rest), 2);

        for ($i = 0; $i < $pairs; $i++) {
            if (Value::looseEquals($subject, $rest[$i * 2])) {
                return $rest[$i * 2 + 1];
            }
        }

        // Si sobra un argumento suelto al final, es el valor por defecto.
        return count($rest) % 2 === 1 ? $rest[count($rest) - 1] : null;
    }

    /** Modificador de atributo, configurable por plantilla. */
    private static function attributeMod(int|float $score, array $settings): int|float
    {
        $base = $settings['mod_base'] ?? 10;
        $divisor = $settings['mod_divisor'] ?? 2;

        if ((float) $divisor === 0.0) {
            return 0;
        }

        return floor(($score - $base) / $divisor);
    }

    /** Bonificador de competencia por nivel: 2 + floor((nivel-1)/4) en 5e. */
    private static function proficiency(int|float $level, array $settings): int|float
    {
        if (isset($settings['proficiency_table']) && is_array($settings['proficiency_table'])) {
            $table = $settings['proficiency_table'];
            $key = (string) (int) $level;

            if (array_key_exists($key, $table)) {
                return Value::toNumber($table[$key]);
            }
        }

        $base = $settings['prof_base'] ?? 2;
        $step = $settings['prof_step'] ?? 4;

        if ((float) $step === 0.0) {
            return $base;
        }

        return $base + floor((max(1, $level) - 1) / $step);
    }

    /** Tabla de consulta definida en la plantilla (Fase 3). */
    private static function lookup(string $table, mixed $key, mixed $default, array $settings): mixed
    {
        $tables = $settings['lookups'] ?? [];
        $rows = $tables[$table] ?? null;

        if (! is_array($rows)) {
            return $default;
        }

        $k = Value::toString($key);

        return array_key_exists($k, $rows) ? $rows[$k] : $default;
    }

    /**
     * Índice del primer umbral que el valor NO alcanza.
     * tier(7, [5, 10, 15]) → 1   (pasa 5, no llega a 10)
     */
    private static function tier(int|float $value, array $thresholds): int
    {
        $tier = 0;

        foreach ($thresholds as $threshold) {
            if ($value >= Value::toNumber($threshold)) {
                $tier++;
            } else {
                break;
            }
        }

        return $tier;
    }

    /**
     * Valor medio de una expresión de dados, sin tirar: "2d6+3" → 10.
     * Sirve para estimaciones en la hoja (daño medio de un arma, por ejemplo).
     */
    private static function averageRoll(string $expression): float
    {
        $total = 0.0;
        $expr = str_replace(' ', '', $expression);

        if (! preg_match_all('/([+-]?)(\d*)d(\d+)|([+-]?\d+)(?!d)/i', $expr, $matches, PREG_SET_ORDER)) {
            return 0.0;
        }

        foreach ($matches as $m) {
            if (($m[3] ?? '') !== '') {
                $sign = $m[1] === '-' ? -1 : 1;
                $count = $m[2] === '' ? 1 : (int) $m[2];
                $sides = (int) $m[3];
                $total += $sign * $count * ($sides + 1) / 2;
            } elseif (($m[4] ?? '') !== '') {
                $total += (float) $m[4];
            }
        }

        return $total;
    }
}
