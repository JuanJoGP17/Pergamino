<?php

namespace App\Domain\Formula;

/**
 * Tokens → AST, por escalada de precedencia.
 *
 * Este es el ÚNICO analizador del sistema: el navegador no analiza nada, recibe
 * el árbol ya construido. Por eso la gramática solo puede equivocarse en un
 * sitio.
 *
 * Precedencia, de menor a mayor:
 *   ?:  <  ||  <  &&  <  comparación/in/contains  <  + -  <  * / %  <  unario
 */
final class Parser
{
    private int $i = 0;

    /** @param array<int,array{t:string,v:mixed,p:int}> $tokens */
    private function __construct(private array $tokens, private string $source) {}

    /** Tope de tokens: acota la recursión del analizador antes de empezar. */
    private const MAX_TOKENS = 1024;

    public static function parse(string $source): array
    {
        $tokens = Lexer::tokenize($source);

        // El analizador es descendente recursivo, así que la profundidad de
        // recursión está acotada por el número de tokens. Comprobarlo aquí
        // evita agotar la pila antes de poder medir el árbol.
        if (count($tokens) > self::MAX_TOKENS) {
            throw FormulaSyntaxException::at(
                'la fórmula tiene demasiados elementos ('.count($tokens).', el máximo es '.self::MAX_TOKENS.')',
                0,
                $source,
            );
        }

        $parser = new self($tokens, $source);
        $ast = $parser->expression();

        if ($parser->peek()['t'] !== 'eof') {
            $tok = $parser->peek();
            throw FormulaSyntaxException::at(
                "sobra «{$parser->describe($tok)}» al final de la expresión",
                $tok['p'],
                $source,
            );
        }

        $parser->assertWithinLimits($ast);

        return $ast;
    }

    // ------------------------------------------------------------ gramática

    /**
     * Profundidad de anidamiento mientras se analiza.
     *
     * Los paréntesis no generan nodo en el AST, así que `((((1))))` produce un
     * árbol de profundidad 1 y assertWithinLimits() no lo vería. Se cuenta aquí,
     * en cada entrada a expression(), que es por donde pasa todo anidamiento:
     * paréntesis, argumentos, listas, índices y ramas del ternario.
     */
    private int $nesting = 0;

    private function expression(): array
    {
        if (++$this->nesting > Ast::MAX_DEPTH) {
            throw FormulaSyntaxException::at(
                'la fórmula anida demasiado (el máximo es '.Ast::MAX_DEPTH.' niveles)',
                $this->peek()['p'],
                $this->source,
            );
        }

        try {
            return $this->ternary();
        } finally {
            $this->nesting--;
        }
    }

    private function ternary(): array
    {
        $cond = $this->orExpr();

        if (! $this->matchPunct('?')) {
            return $cond;
        }

        $then = $this->expression();

        if (! $this->matchPunct(':')) {
            throw $this->unexpected('se esperaba «:» para cerrar el condicional');
        }

        return Ast::ternary($cond, $then, $this->expression());
    }

    private function orExpr(): array
    {
        $left = $this->andExpr();

        while ($this->matchOp('||')) {
            $left = Ast::binary('||', $left, $this->andExpr());
        }

        return $left;
    }

    private function andExpr(): array
    {
        $left = $this->comparison();

        while ($this->matchOp('&&')) {
            $left = Ast::binary('&&', $left, $this->comparison());
        }

        return $left;
    }

    private function comparison(): array
    {
        $left = $this->additive();

        // Sin encadenamiento: `a < b < c` es casi siempre un error de quien
        // escribe, no una intención. Se acepta un solo operador de comparación.
        foreach (['==', '!=', '<=', '>=', '<', '>', 'in', 'contains'] as $op) {
            if ($this->matchOp($op)) {
                return Ast::binary($op, $left, $this->additive());
            }
        }

        return $left;
    }

    private function additive(): array
    {
        $left = $this->multiplicative();

        while (true) {
            if ($this->matchOp('+')) {
                $left = Ast::binary('+', $left, $this->multiplicative());
            } elseif ($this->matchOp('-')) {
                $left = Ast::binary('-', $left, $this->multiplicative());
            } else {
                return $left;
            }
        }
    }

    private function multiplicative(): array
    {
        $left = $this->unary();

        while (true) {
            if ($this->matchOp('*')) {
                $left = Ast::binary('*', $left, $this->unary());
            } elseif ($this->matchOp('/')) {
                $left = Ast::binary('/', $left, $this->unary());
            } elseif ($this->matchOp('%')) {
                $left = Ast::binary('%', $left, $this->unary());
            } else {
                return $left;
            }
        }
    }

    private function unary(): array
    {
        if ($this->matchOp('-')) {
            return Ast::unary('-', $this->unary());
        }

        if ($this->matchOp('+')) {
            return $this->unary();   // el más unario no hace nada
        }

        if ($this->matchOp('!')) {
            return Ast::unary('!', $this->unary());
        }

        return $this->primary();
    }

    private function primary(): array
    {
        $tok = $this->peek();

        switch ($tok['t']) {
            case 'num':
                $this->i++;

                return Ast::num($tok['v']);

            case 'str':
                $this->i++;

                return Ast::str($tok['v']);

            case 'bool':
                $this->i++;

                return Ast::bool($tok['v']);

            case 'null':
                $this->i++;

                return Ast::nullNode();

            case 'ref':
                $this->i++;

                return $this->reference($tok['v']);

            case 'ident':
                $this->i++;

                if (! $this->matchPunct('(')) {
                    throw FormulaSyntaxException::at(
                        "«{$tok['v']}» no es una función ni un campo. ¿Querías escribir «@{$tok['v']}»?",
                        $tok['p'],
                        $this->source,
                    );
                }

                return Ast::call($tok['v'], $this->arguments(')'));

            case '[':
                $this->i++;

                return Ast::arr($this->arguments(']'));

            case '(':
                $this->i++;
                $inner = $this->expression();

                if (! $this->matchPunct(')')) {
                    throw $this->unexpected('falta cerrar el paréntesis');
                }

                return $inner;
        }

        throw $this->unexpected('se esperaba un valor');
    }

    /**
     * Referencia con su índice y sus propiedades:
     *
     *   @fuerza            → key=fuerza
     *
     *   @fuerza.mod        → key=fuerza, path=[mod]
     *
     *   @ataques[*].bonus  → key=ataques, idx='*', path=[bonus]
     *
     *   @ataques[0].nombre → key=ataques, idx=nodo(0), path=[nombre]
     */
    private function reference(string $key): array
    {
        $idx = null;

        if ($this->matchPunct('[')) {
            if ($this->peek()['t'] === 'op' && $this->peek()['v'] === '*') {
                $this->i++;
                $idx = '*';
            } else {
                $idx = $this->expression();
            }

            if (! $this->matchPunct(']')) {
                throw $this->unexpected('falta cerrar el corchete de la referencia');
            }
        }

        $path = [];
        while ($this->matchPunct('.')) {
            $tok = $this->peek();

            if ($tok['t'] !== 'ident') {
                throw $this->unexpected('se esperaba el nombre de una propiedad después del punto');
            }

            $this->i++;
            $path[] = $tok['v'];
        }

        return Ast::ref($key, $path, $idx);
    }

    /** @return array<int,array> */
    private function arguments(string $closer): array
    {
        $args = [];

        if ($this->matchPunct($closer)) {
            return $args;
        }

        do {
            $args[] = $this->expression();
        } while ($this->matchPunct(','));

        if (! $this->matchPunct($closer)) {
            throw $this->unexpected("falta cerrar con «{$closer}»");
        }

        return $args;
    }

    // ---------------------------------------------------------------- utils

    private function peek(): array
    {
        return $this->tokens[$this->i] ?? ['t' => 'eof', 'v' => null, 'p' => 0];
    }

    private function matchOp(string $op): bool
    {
        $tok = $this->peek();

        if ($tok['t'] === 'op' && $tok['v'] === $op) {
            $this->i++;

            return true;
        }

        return false;
    }

    private function matchPunct(string $p): bool
    {
        if ($this->peek()['t'] === $p) {
            $this->i++;

            return true;
        }

        return false;
    }

    private function unexpected(string $message): FormulaSyntaxException
    {
        $tok = $this->peek();

        return FormulaSyntaxException::at(
            $message.', se encontró '.$this->describe($tok),
            $tok['p'],
            $this->source,
        );
    }

    private function describe(array $tok): string
    {
        return match ($tok['t']) {
            'eof' => 'el final de la fórmula',
            'num', 'str', 'bool' => '«'.var_export($tok['v'], true).'»',
            'ref' => '«@'.$tok['v'].'»',
            default => '«'.$tok['v'].'»',
        };
    }

    /**
     * Sin estos topes, `((((((…))))))` o una expresión generada a mano podrían
     * agotar la pila al evaluar. Se comprueban al compilar, una sola vez.
     */
    private function assertWithinLimits(array $ast): void
    {
        $depth = Ast::depth($ast);
        if ($depth > Ast::MAX_DEPTH) {
            throw FormulaSyntaxException::at(
                "la fórmula anida demasiado ({$depth} niveles, el máximo es ".Ast::MAX_DEPTH.')',
                0,
                $this->source,
            );
        }

        $nodes = Ast::count($ast);
        if ($nodes > Ast::MAX_NODES) {
            throw FormulaSyntaxException::at(
                "la fórmula es demasiado larga ({$nodes} nodos, el máximo es ".Ast::MAX_NODES.')',
                0,
                $this->source,
            );
        }
    }
}
