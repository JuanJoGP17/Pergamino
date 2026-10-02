<?php

namespace App\Domain\Formula;

/**
 * Texto → tokens.
 *
 * Un token es un array simple: ['t' => tipo, 'v' => valor, 'p' => posición].
 * La posición se arrastra para poder señalar la columna exacta en los mensajes
 * de error: "carácter inesperado «$» en la posición 12" es accionable.
 */
final class Lexer
{
    private const PUNCT = ['(', ')', '[', ']', ',', '.', '?', ':'];

    /** Operadores, del más largo al más corto: el orden importa al hacer match. */
    private const OPERATORS = ['<=', '>=', '==', '!=', '&&', '||', '+', '-', '*', '/', '%', '<', '>', '!'];

    /** Palabras que son operadores o literales, no identificadores. */
    private const KEYWORDS = [
        'true' => 'bool', 'false' => 'bool', 'null' => 'null',
        'and' => 'op', 'or' => 'op', 'not' => 'op',
        'in' => 'op', 'contains' => 'op',
    ];

    private int $pos = 0;

    private int $len = 0;

    public function __construct(private string $source)
    {
        $this->len = mb_strlen($source);
    }

    /** @return array<int,array{t:string,v:mixed,p:int}> */
    public static function tokenize(string $source): array
    {
        return (new self($source))->run();
    }

    /** @return array<int,array{t:string,v:mixed,p:int}> */
    public function run(): array
    {
        $tokens = [];

        while ($this->pos < $this->len) {
            $c = $this->at($this->pos);

            if (trim($c) === '') {
                $this->pos++;

                continue;
            }

            $start = $this->pos;

            // --- número -------------------------------------------------
            if ($this->isDigit($c) || ($c === '.' && $this->isDigit($this->at($this->pos + 1)))) {
                $tokens[] = ['t' => 'num', 'v' => $this->readNumber(), 'p' => $start];

                continue;
            }

            // --- cadena -------------------------------------------------
            if ($c === '"' || $c === "'") {
                $tokens[] = ['t' => 'str', 'v' => $this->readString($c), 'p' => $start];

                continue;
            }

            // --- referencia a campo: @clave -----------------------------
            if ($c === '@') {
                $this->pos++;
                $name = $this->readIdentifier();

                if ($name === '') {
                    throw FormulaSyntaxException::at('se esperaba el nombre de un campo después de «@»', $start, $this->source);
                }

                $tokens[] = ['t' => 'ref', 'v' => $name, 'p' => $start];

                continue;
            }

            // --- identificador o palabra clave --------------------------
            if ($this->isIdentStart($c)) {
                $word = $this->readIdentifier();
                $kind = self::KEYWORDS[$word] ?? 'ident';

                $tokens[] = match ($kind) {
                    'bool' => ['t' => 'bool', 'v' => $word === 'true', 'p' => $start],
                    'null' => ['t' => 'null', 'v' => null, 'p' => $start],
                    'op' => ['t' => 'op', 'v' => $this->normalizeWordOperator($word), 'p' => $start],
                    default => ['t' => 'ident', 'v' => $word, 'p' => $start],
                };

                continue;
            }

            // --- operador -----------------------------------------------
            $op = $this->matchOperator();
            if ($op !== null) {
                $tokens[] = ['t' => 'op', 'v' => $op, 'p' => $start];

                continue;
            }

            // --- puntuación ---------------------------------------------
            if (in_array($c, self::PUNCT, true)) {
                $this->pos++;
                $tokens[] = ['t' => $c, 'v' => $c, 'p' => $start];

                continue;
            }

            throw FormulaSyntaxException::at("carácter inesperado «{$c}»", $start, $this->source);
        }

        $tokens[] = ['t' => 'eof', 'v' => null, 'p' => $this->len];

        return $tokens;
    }

    // ------------------------------------------------------------- lectura

    private function readNumber(): float
    {
        $start = $this->pos;
        $seenDot = false;

        while ($this->pos < $this->len) {
            $c = $this->at($this->pos);

            if ($this->isDigit($c)) {
                $this->pos++;

                continue;
            }

            if ($c === '.' && ! $seenDot && $this->isDigit($this->at($this->pos + 1))) {
                $seenDot = true;
                $this->pos++;

                continue;
            }

            break;
        }

        return (float) mb_substr($this->source, $start, $this->pos - $start);
    }

    private function readString(string $quote): string
    {
        $this->pos++;   // comilla de apertura
        $out = '';

        while ($this->pos < $this->len) {
            $c = $this->at($this->pos);

            if ($c === '\\') {
                $next = $this->at($this->pos + 1);
                $out .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    '\\' => '\\',
                    '"' => '"',
                    "'" => "'",
                    default => $next,
                };
                $this->pos += 2;

                continue;
            }

            if ($c === $quote) {
                $this->pos++;

                return $out;
            }

            $out .= $c;
            $this->pos++;
        }

        throw FormulaSyntaxException::at('falta cerrar la comilla', $this->pos, $this->source);
    }

    private function readIdentifier(): string
    {
        $start = $this->pos;

        while ($this->pos < $this->len && $this->isIdentPart($this->at($this->pos))) {
            $this->pos++;
        }

        return mb_substr($this->source, $start, $this->pos - $start);
    }

    private function matchOperator(): ?string
    {
        foreach (self::OPERATORS as $op) {
            $n = strlen($op);

            if (mb_substr($this->source, $this->pos, $n) === $op) {
                $this->pos += $n;

                return $op;
            }
        }

        return null;
    }

    /** `and` / `or` / `not` son azúcar sobre los símbolos. */
    private function normalizeWordOperator(string $word): string
    {
        return match ($word) {
            'and' => '&&',
            'or' => '||',
            'not' => '!',
            default => $word,   // in, contains
        };
    }

    // -------------------------------------------------------------- utils

    private function at(int $i): string
    {
        return $i >= 0 && $i < $this->len ? mb_substr($this->source, $i, 1) : '';
    }

    private function isDigit(string $c): bool
    {
        return $c !== '' && $c >= '0' && $c <= '9';
    }

    private function isIdentStart(string $c): bool
    {
        return $c !== '' && (ctype_alpha($c) || $c === '_' || strlen($c) > 1);
    }

    private function isIdentPart(string $c): bool
    {
        return $c !== '' && (ctype_alnum($c) || $c === '_' || strlen($c) > 1);
    }
}
