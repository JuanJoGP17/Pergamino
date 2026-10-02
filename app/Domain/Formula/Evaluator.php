<?php

namespace App\Domain\Formula;

/**
 * AST + contexto → valor.
 *
 * Gemelo: `resources/js/formula/evaluate.js`. Los dos recorren exactamente el
 * mismo árbol (el que produjo este PHP al publicar), así que solo pueden
 * separarse en la semántica, que es justo lo que cubre la batería compartida.
 *
 * El contexto es plano:
 *   values   → valores escritos por el usuario, por clave de campo
 *   computed → valores ya calculados y propiedades derivadas (@fuerza.mod)
 *   settings → configuración de la plantilla para mod(), prof() y lookup()
 */
final class Evaluator
{
    public function __construct(
        private array $values = [],
        private array $computed = [],
        private array $settings = [],
    ) {}

    public static function make(array $values, array $computed = [], array $settings = []): self
    {
        return new self($values, $computed, $settings);
    }

    public function evaluate(array $node): mixed
    {
        return match ($node['n']) {
            'num', 'str', 'bool' => $node['v'],
            'null' => null,
            'arr' => array_map(fn (array $item) => $this->evaluate($item), $node['items']),
            'ref' => $this->resolveReference($node),
            'un' => $this->unary($node),
            'bin' => $this->binary($node),
            'ter' => Value::toBool($this->evaluate($node['c']))
                ? $this->evaluate($node['a'])
                : $this->evaluate($node['b']),
            'call' => $this->call($node),
            default => throw new FormulaRuntimeException("nodo desconocido: {$node['n']}"),
        };
    }

    // -------------------------------------------------------------- nodos

    /**
     * Resuelve `@clave`, `@clave.propiedad`, `@lista[*].columna`, `@lista[0].x`.
     *
     * Los derivados (computed) tienen prioridad sobre los valores crudos: si un
     * campo `attribute` guarda 16 en `values` y su `.mod` vive en `computed`,
     * `@fuerza` debe dar 16 y `@fuerza.mod` debe dar 3.
     */
    private function resolveReference(array $node): mixed
    {
        $key = $node['key'];
        $path = $node['path'] ?? [];
        $idx = $node['idx'] ?? null;

        // @self.nivel es azúcar para @nivel: se descarta el alias y el primer
        // tramo del camino pasa a ser la clave del campo.
        if ($key === Ast::SELF) {
            if ($path === []) {
                return null;
            }

            $key = array_shift($path);
        }

        // La propiedad derivada se busca primero en computed.
        if ($path !== [] && isset($this->computed[$key])) {
            $found = $this->digPath($this->computed[$key], $path);

            if ($found !== null) {
                return $found;
            }
        }

        $base = $this->values[$key] ?? $this->computed[$key] ?? null;

        // Índice sobre una lista: @ataques[*] o @ataques[2]
        if ($idx !== null) {
            $rows = Value::toArray($base);

            if ($idx === '*') {
                // La columna de todas las filas.
                return array_map(fn ($row) => $this->digPath($row, $path), $rows);
            }

            $i = (int) Value::toNumber($this->evaluate($idx));
            $base = $rows[$i] ?? null;
        }

        return $path === [] ? $base : $this->digPath($base, $path);
    }

    /** @param array<int,string> $path */
    private function digPath(mixed $value, array $path): mixed
    {
        foreach ($path as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];

                continue;
            }

            return null;
        }

        return $value;
    }

    private function unary(array $node): mixed
    {
        $x = $this->evaluate($node['x']);

        return match ($node['op']) {
            '-' => $this->negate($x),
            '!' => ! Value::toBool($x),
            default => throw new FormulaRuntimeException("operador unario desconocido: {$node['op']}"),
        };
    }

    private function binary(array $node): mixed
    {
        $op = $node['op'];

        // Cortocircuito: `@nivel > 0 && 10 / @nivel > 1` no debe dividir por
        // cero cuando el nivel es 0.
        if ($op === '&&') {
            return Value::toBool($this->evaluate($node['l']))
                ? Value::toBool($this->evaluate($node['r']))
                : false;
        }

        if ($op === '||') {
            return Value::toBool($this->evaluate($node['l']))
                ? true
                : Value::toBool($this->evaluate($node['r']));
        }

        $l = $this->evaluate($node['l']);
        $r = $this->evaluate($node['r']);

        return match ($op) {
            '+', '-', '*', '/', '%' => $this->arithmetic($op, $l, $r),
            '==' => Value::looseEquals($l, $r),
            '!=' => ! Value::looseEquals($l, $r),
            '<' => Value::compare($l, $r) < 0,
            '<=' => Value::compare($l, $r) <= 0,
            '>' => Value::compare($l, $r) > 0,
            '>=' => Value::compare($l, $r) >= 0,
            'in' => Value::contains($r, $l),
            'contains' => Value::contains($l, $r),
            default => throw new FormulaRuntimeException("operador desconocido: {$op}"),
        };
    }

    /**
     * Aritmética con difusión sobre listas.
     *
     * Si algún operando es una lista, la operación se aplica elemento a
     * elemento, que es lo que hace posible la carga de un inventario:
     *
     *   sum(@inventario[*].peso * @inventario[*].cantidad)
     *   [3, 2] * [1, 5]  → [3, 10]
     *   [3, 2] * 2       → [6, 4]
     *
     * Con dos listas de distinta longitud, la corta se completa con null (que
     * vale 0): el resultado tiene tantos elementos como la más larga. Los
     * repeaters dan siempre columnas de la misma longitud, así que esto solo
     * importa con listas escritas a mano.
     */
    private function arithmetic(string $op, mixed $l, mixed $r): mixed
    {
        if (is_array($l) || is_array($r)) {
            $ls = is_array($l) ? array_values($l) : null;
            $rs = is_array($r) ? array_values($r) : null;
            $len = max(count($ls ?? []), count($rs ?? []));

            $out = [];
            for ($i = 0; $i < $len; $i++) {
                $out[] = $this->arithmetic(
                    $op,
                    $ls === null ? $l : ($ls[$i] ?? null),
                    $rs === null ? $r : ($rs[$i] ?? null),
                );
            }

            return $out;
        }

        return match ($op) {
            '+' => $this->add($l, $r),
            '-' => Value::number(Value::toNumber($l) - Value::toNumber($r)),
            '*' => Value::number(Value::toNumber($l) * Value::toNumber($r)),
            '/' => $this->divide($l, $r),
            '%' => $this->modulo($l, $r),
        };
    }

    private function negate(mixed $x): mixed
    {
        if (is_array($x)) {
            return array_map(fn ($item) => $this->negate($item), array_values($x));
        }

        return Value::number(-Value::toNumber($x));
    }

    /**
     * `+` suma números y concatena si alguno de los dos es texto no numérico.
     * Es la única sobrecarga del lenguaje, y evita tener que explicar por qué
     * `concat()` es obligatorio para juntar dos palabras.
     */
    private function add(mixed $l, mixed $r): mixed
    {
        $lIsText = is_string($l) && ! is_numeric(trim($l)) && trim($l) !== '';
        $rIsText = is_string($r) && ! is_numeric(trim($r)) && trim($r) !== '';

        if ($lIsText || $rIsText) {
            return Value::toString($l).Value::toString($r);
        }

        return Value::number(Value::toNumber($l) + Value::toNumber($r));
    }

    private function divide(mixed $l, mixed $r): int|float
    {
        $divisor = Value::toNumber($r);

        if ((float) $divisor === 0.0) {
            throw FormulaRuntimeException::divisionByZero();
        }

        return Value::number(Value::toNumber($l) / $divisor);
    }

    private function modulo(mixed $l, mixed $r): int|float
    {
        $divisor = Value::toNumber($r);

        if ((float) $divisor === 0.0) {
            throw FormulaRuntimeException::divisionByZero();
        }

        return Value::number(fmod(Value::toNumber($l), $divisor));
    }

    private function call(array $node): mixed
    {
        $name = $node['fn'];

        // `if` es perezoso: solo se evalúa la rama elegida. Si no, algo como
        // if(@divisor == 0, 0, 100 / @divisor) reventaría siempre.
        if ($name === 'if') {
            FunctionRegistry::checkArity('if', count($node['args']));

            return Value::toBool($this->evaluate($node['args'][0]))
                ? $this->evaluate($node['args'][1])
                : $this->evaluate($node['args'][2]);
        }

        $args = array_map(fn (array $arg) => $this->evaluate($arg), $node['args']);

        return FunctionRegistry::call($name, $args, $this->settings);
    }
}
