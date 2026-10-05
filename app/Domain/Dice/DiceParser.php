<?php

namespace App\Domain\Dice;

/**
 * Notación de dados (§7 del plan) → árbol.
 *
 *   NdM            4d6           d% = d100, dF = dado Fudge (−1, 0, +1)
 *   +N / -N        1d20+5        y aritmética: (2d6+3)*2, 1d8/2 (redondea abajo)
 *   khN / klN      4d6kh3        quedarse con los N más altos / bajos (k = kh)
 *   dhN / dlN      4d6dl1        descartar los N más altos / bajos
 *   ! / !N         3d6!  1d6!>=5 explosivos: relanza el máximo, o lo que cumpla
 *   rN / roN       2d6r1  1d20ro<3  relanzar (r: mientras salga; ro: una vez)
 *   >=N, >N…       5d10>=7       contar éxitos (Storyteller): el valor es cuántos
 *   csN / cfN      1d20cs19      umbral de crítico (por defecto >=) / pifia (<=)
 *
 * Donde un modificador lleva número, puede llevar comparación delante:
 * `r<3`, `!>=5`, `cs>=19`. Un número a secas en r/ro es «igual a» (r1 relanza
 * los unos); en !, cs y >= es «mayor o igual»; en cf, «menor o igual».
 *
 * La ventaja y la desventaja no se escriben aquí: son el `mode` de la tirada
 * (DiceRoller), que convierte el primer 1d20 en 2d20kh1 / 2d20kl1.
 *
 * Árbol (arrays, como el de fórmulas):
 *   ['n' => 'num', 'v' => 5]
 *   ['n' => 'dice', 'count' => 4, 'sides' => 6 | 'F', 'mods' => [...], 'text' => '4d6kh3']
 *   ['n' => 'bin', 'op' => '+', 'l' => …, 'r' => …]
 *   ['n' => 'neg', 'x' => …]
 */
final class DiceParser
{
    public const MAX_LENGTH = 200;

    public const MAX_DEPTH = 16;

    public const MAX_COUNT = 100;

    public const MAX_SIDES = 1000;

    private string $s = '';

    private int $i = 0;

    private int $depth = 0;

    public function parse(string $expression): array
    {
        // Sin espacios y en minúsculas: «1D20 + 5» es lo mismo que «1d20+5».
        $this->s = mb_strtolower(preg_replace('/\s+/u', '', $expression));
        $this->i = 0;
        $this->depth = 0;

        if ($this->s === '') {
            throw new DiceException('La tirada está vacía.');
        }

        if (strlen($this->s) > self::MAX_LENGTH) {
            throw new DiceException('La tirada es demasiado larga.');
        }

        if (! preg_match('/^[0-9d%f+\-*\/()khlr!o<>=cs]+$/', $this->s)) {
            throw new DiceException('La tirada lleva caracteres que no son de dados.');
        }

        $node = $this->sum();

        if ($this->i < strlen($this->s)) {
            throw DiceException::at("No entiendo «{$this->s[$this->i]}»", $this->i);
        }

        return $node;
    }

    // ------------------------------------------------------------ gramática

    private function sum(): array
    {
        $node = $this->product();

        while (in_array($this->peek(), ['+', '-'], true)) {
            $op = $this->next();
            $node = ['n' => 'bin', 'op' => $op, 'l' => $node, 'r' => $this->product()];
        }

        return $node;
    }

    private function product(): array
    {
        $node = $this->unary();

        while (in_array($this->peek(), ['*', '/'], true)) {
            $op = $this->next();
            $node = ['n' => 'bin', 'op' => $op, 'l' => $node, 'r' => $this->unary()];
        }

        return $node;
    }

    private function unary(): array
    {
        if ($this->peek() === '-') {
            $this->i++;

            return ['n' => 'neg', 'x' => $this->unary()];
        }

        if ($this->peek() === '+') {
            $this->i++;

            return $this->unary();
        }

        return $this->primary();
    }

    private function primary(): array
    {
        if ($this->peek() === '(') {
            if (++$this->depth > self::MAX_DEPTH) {
                throw new DiceException('Demasiados paréntesis anidados.');
            }

            $this->i++;
            $node = $this->sum();

            if ($this->peek() !== ')') {
                throw DiceException::at('Falta cerrar un paréntesis', $this->i);
            }

            $this->i++;
            $this->depth--;

            return $node;
        }

        $start = $this->i;
        $number = $this->number();

        if ($this->peek() === 'd') {
            return $this->dice($number ?? 1, $start);
        }

        if ($number === null) {
            throw DiceException::at($this->peek() === null ? 'La tirada acaba a medias' : "No entiendo «{$this->peek()}»", $this->i);
        }

        return ['n' => 'num', 'v' => $number];
    }

    private function dice(int $count, int $start): array
    {
        $this->i++; // la d

        if ($this->peek() === 'f') {
            $this->i++;
            $sides = 'F';
        } elseif ($this->peek() === '%') {
            $this->i++;
            $sides = 100;
        } else {
            $sides = $this->number() ?? throw DiceException::at('Falta el número de caras del dado', $this->i);
        }

        if ($count < 1 || $count > self::MAX_COUNT) {
            throw DiceException::at('Se pueden tirar de 1 a '.self::MAX_COUNT.' dados a la vez', $start);
        }

        if ($sides !== 'F' && ($sides < 1 || $sides > self::MAX_SIDES)) {
            throw DiceException::at('Un dado tiene de 1 a '.self::MAX_SIDES.' caras', $start);
        }

        $mods = [];

        while (($mod = $this->modifier()) !== null) {
            $mods[] = $mod;
        }

        return ['n' => 'dice', 'count' => $count, 'sides' => $sides, 'mods' => $mods, 'text' => substr($this->s, $start, $this->i - $start)];
    }

    /** Un modificador tras el dado, o null si lo que sigue ya no lo es. */
    private function modifier(): ?array
    {
        $rest = substr($this->s, $this->i);

        foreach (['kh' => 'kh', 'kl' => 'kl', 'dh' => 'dh', 'dl' => 'dl'] as $prefix => $kind) {
            if (str_starts_with($rest, $prefix)) {
                $this->i += 2;

                return ['m' => $kind, 'n' => $this->number() ?? 1];
            }
        }

        // `k` a secas es «quedarse con los más altos»; no se confunde con kh/kl
        // porque esos se han mirado antes.
        if (str_starts_with($rest, 'k')) {
            $this->i++;

            return ['m' => 'kh', 'n' => $this->number() ?? 1];
        }

        if (str_starts_with($rest, '!')) {
            $this->i++;

            return ['m' => 'explode', 'cmp' => $this->comparison('>=')];
        }

        if (str_starts_with($rest, 'ro')) {
            $this->i += 2;

            return ['m' => 'reroll', 'once' => true, 'cmp' => $this->comparison('=', required: true)];
        }

        if (str_starts_with($rest, 'r')) {
            $this->i++;

            return ['m' => 'reroll', 'once' => false, 'cmp' => $this->comparison('=', required: true)];
        }

        if (str_starts_with($rest, 'cs')) {
            $this->i += 2;

            return ['m' => 'crit', 'cmp' => $this->comparison('>=', required: true)];
        }

        if (str_starts_with($rest, 'cf')) {
            $this->i += 2;

            return ['m' => 'fumble', 'cmp' => $this->comparison('<=', required: true)];
        }

        if (preg_match('/^(>=|<=|>|<|=)/', $rest)) {
            return ['m' => 'success', 'cmp' => $this->comparison('>=', required: true)];
        }

        return null;
    }

    /**
     * Comparación opcional + número: «>=5», «<3», «5» (con el operador por
     * defecto). Sin número y sin ser obligatorio → null (p. ej. «!» a secas).
     *
     * @return array{op:string, v:int}|null
     */
    private function comparison(string $default, bool $required = false): ?array
    {
        $op = $default;

        if (preg_match('/^(>=|<=|>|<|=)/', substr($this->s, $this->i), $m)) {
            $op = $m[1];
            $this->i += strlen($m[1]);
            $required = true;
        }

        $value = $this->number();

        if ($value === null) {
            if ($required) {
                throw DiceException::at('Falta un número', $this->i);
            }

            return null;
        }

        return ['op' => $op, 'v' => $value];
    }

    private function number(): ?int
    {
        if (! preg_match('/^\d+/', substr($this->s, $this->i), $m)) {
            return null;
        }

        if (strlen($m[0]) > 7) {
            throw DiceException::at('Número demasiado grande', $this->i);
        }

        $this->i += strlen($m[0]);

        return (int) $m[0];
    }

    private function peek(): ?string
    {
        return $this->s[$this->i] ?? null;
    }

    private function next(): string
    {
        return $this->s[$this->i++];
    }
}
