<?php

namespace App\Domain\Formula;

/**
 * Nodos del AST y utilidades sobre él.
 *
 * El AST son **arrays planos**, no objetos. Es deliberado: así se serializa a
 * JSON sin trabajo, se guarda dentro de compiled_schema y el evaluador de
 * JavaScript recibe exactamente el mismo árbol que evaluó PHP. Ese es el
 * mecanismo que impide que los dos motores se separen (ver README.md).
 *
 * Forma de cada nodo — la clave `n` es el tipo:
 *
 *   ['n'=>'num',  'v'=>3]
 *   ['n'=>'str',  'v'=>'texto']
 *   ['n'=>'bool', 'v'=>true]
 *   ['n'=>'null']
 *   ['n'=>'arr',  'items'=>[nodo, …]]
 *   ['n'=>'ref',  'key'=>'fuerza', 'path'=>['mod'], 'idx'=>null|'*'|nodo]
 *   ['n'=>'un',   'op'=>'-', 'x'=>nodo]
 *   ['n'=>'bin',  'op'=>'+', 'l'=>nodo, 'r'=>nodo]
 *   ['n'=>'ter',  'c'=>nodo, 'a'=>nodo, 'b'=>nodo]
 *   ['n'=>'call', 'fn'=>'floor', 'args'=>[nodo, …]]
 */
final class Ast
{
    public const MAX_DEPTH = 64;

    public const MAX_NODES = 512;

    /**
     * Alias de la propia hoja: `@self.nivel` es lo mismo que `@nivel`. Existe
     * para poder desambiguar cuando en el futuro haya otros ámbitos (la mesa,
     * otra hoja referenciada). No es un campo, así que nunca cuenta como
     * dependencia ni el validador debe exigir que exista.
     */
    public const SELF = 'self';

    // ------------------------------------------------------------ factories

    public static function num(float $v): array
    {
        return ['n' => 'num', 'v' => Value::number($v)];
    }

    public static function str(string $v): array
    {
        return ['n' => 'str', 'v' => $v];
    }

    public static function bool(bool $v): array
    {
        return ['n' => 'bool', 'v' => $v];
    }

    public static function nullNode(): array
    {
        return ['n' => 'null'];
    }

    public static function arr(array $items): array
    {
        return ['n' => 'arr', 'items' => $items];
    }

    public static function ref(string $key, array $path = [], mixed $idx = null): array
    {
        return ['n' => 'ref', 'key' => $key, 'path' => $path, 'idx' => $idx];
    }

    public static function unary(string $op, array $x): array
    {
        return ['n' => 'un', 'op' => $op, 'x' => $x];
    }

    public static function binary(string $op, array $l, array $r): array
    {
        return ['n' => 'bin', 'op' => $op, 'l' => $l, 'r' => $r];
    }

    public static function ternary(array $c, array $a, array $b): array
    {
        return ['n' => 'ter', 'c' => $c, 'a' => $a, 'b' => $b];
    }

    public static function call(string $fn, array $args): array
    {
        return ['n' => 'call', 'fn' => $fn, 'args' => $args];
    }

    /**
     * Ata `@self` sin propiedad al campo dueño de la fórmula.
     *
     * Dentro de las fórmulas de un campo, `@self` a secas es el valor de ese
     * mismo campo. Es lo que permite escribir UNA `mod_formula` —
     * `floor((@self - 10) / 2)` — y copiarla a los seis atributos sin
     * cambiarla. `@self.nivel` sigue siendo el alias de la hoja.
     *
     * Se reescribe al compilar, en PHP, así que el evaluador de JS recibe una
     * referencia normal y no necesita conocer la regla.
     */
    public static function bindSelf(?array $node, string $key): ?array
    {
        if ($node === null) {
            return null;
        }

        if ($node['n'] === 'ref' && $node['key'] === self::SELF && ($node['path'] ?? []) === []) {
            $node['key'] = $key;
        }

        return match ($node['n']) {
            'un' => [...$node, 'x' => self::bindSelf($node['x'], $key)],
            'bin' => [...$node, 'l' => self::bindSelf($node['l'], $key), 'r' => self::bindSelf($node['r'], $key)],
            'ter' => [...$node,
                'c' => self::bindSelf($node['c'], $key),
                'a' => self::bindSelf($node['a'], $key),
                'b' => self::bindSelf($node['b'], $key),
            ],
            'call' => [...$node, 'args' => array_map(fn ($a) => self::bindSelf($a, $key), $node['args'])],
            'arr' => [...$node, 'items' => array_map(fn ($i) => self::bindSelf($i, $key), $node['items'])],
            'ref' => is_array($node['idx'] ?? null) ? [...$node, 'idx' => self::bindSelf($node['idx'], $key)] : $node,
            default => $node,
        };
    }

    // ------------------------------------------------------------ recorrido

    /**
     * Claves de campo referenciadas por el árbol, sin la propiedad derivada:
     * `@fuerza.mod + @pv.actual` → ['fuerza', 'pv'].
     *
     * Esto sustituye al escáner por expresión regular de la Fase 1: ahora las
     * referencias salen del árbol, así que una arroba dentro de una cadena o un
     * comentario ya no puede confundirse con una referencia — el analizador ya
     * las ha clasificado.
     *
     * @return array<int,string>
     */
    public static function references(?array $node): array
    {
        if ($node === null) {
            return [];
        }

        $out = [];
        self::walk($node, function (array $n) use (&$out) {
            if ($n['n'] !== 'ref') {
                return;
            }

            if ($n['key'] === self::SELF) {
                // @self.nivel depende de «nivel», no de «self».
                if (($n['path'][0] ?? null) !== null) {
                    $out[$n['path'][0]] = true;
                }

                return;
            }

            $out[$n['key']] = true;
        });

        return array_keys($out);
    }

    public static function depth(?array $node): int
    {
        if ($node === null) {
            return 0;
        }

        $max = 0;
        foreach (self::children($node) as $child) {
            $max = max($max, self::depth($child));
        }

        return $max + 1;
    }

    public static function count(?array $node): int
    {
        if ($node === null) {
            return 0;
        }

        $n = 1;
        foreach (self::children($node) as $child) {
            $n += self::count($child);
        }

        return $n;
    }

    /** @param callable(array):void $visitor */
    public static function walk(array $node, callable $visitor): void
    {
        $visitor($node);

        foreach (self::children($node) as $child) {
            self::walk($child, $visitor);
        }
    }

    /** @return array<int,array> */
    public static function children(array $node): array
    {
        return match ($node['n']) {
            'un' => [$node['x']],
            'bin' => [$node['l'], $node['r']],
            'ter' => [$node['c'], $node['a'], $node['b']],
            'call' => $node['args'],
            'arr' => $node['items'],
            // Un índice puede ser una expresión: @ataques[@indice].bonus
            'ref' => is_array($node['idx'] ?? null) ? [$node['idx']] : [],
            default => [],
        };
    }
}
