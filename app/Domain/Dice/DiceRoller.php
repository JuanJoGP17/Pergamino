<?php

namespace App\Domain\Dice;

/**
 * Tira una expresión de dados (§7). Es autoritativo: las tiradas se hacen en
 * el servidor, con random_int(), y el navegador solo enseña el resultado.
 *
 * Resultado estructurado, para poder pintarlo bonito y guardarlo en
 * dice_rolls.result:
 *
 *   {
 *     "expression": "4d6kh3+2", "mode": "normal", "total": 16,
 *     "groups": [ { "notation": "4d6kh3", "sides": 6, "value": 14,
 *                   "dice": [ {"value":5,"kept":true}, {"value":1,"kept":false}, … ] } ],
 *     "crit": false, "fumble": false, "successes": null
 *   }
 *
 * Cada dado puede llevar además "exploded" (salió de una explosión),
 * "rerolled" (los valores que tuvo antes de relanzarse) y "success".
 *
 * Orden dentro de un grupo: relanzar → explotar → quedarse/descartar →
 * contar éxitos. Crítico y pifia se miran en los dados que se quedan.
 */
final class DiceRoller
{
    public const MODES = ['normal', 'advantage', 'disadvantage'];

    /** Dados tirados en total por expresión, contando relanzados y explosiones. */
    public const MAX_DICE = 1000;

    private const MAX_CHAIN = 50;

    private int $rolled = 0;

    private array $groups = [];

    public function __construct(private Rng $rng = new Rng) {}

    public function roll(string $expression, string $mode = 'normal'): array
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'normal';
        $ast = (new DiceParser)->parse($expression);

        if ($mode !== 'normal') {
            $ast = $this->applyMode($ast, $mode);
        }

        $this->rolled = 0;
        $this->groups = [];

        $total = $this->evaluate($ast);

        $successGroups = array_filter($this->groups, fn ($g) => $g['successes'] !== null);

        return [
            'expression' => $expression,
            'mode' => $mode,
            'total' => $total,
            'groups' => array_map(fn ($g) => array_diff_key($g, ['successes' => 1]), $this->groups),
            'crit' => (bool) array_filter(array_column($this->groups, 'crit')),
            'fumble' => (bool) array_filter(array_column($this->groups, 'fumble')),
            'successes' => $successGroups === [] ? null : array_sum(array_column($successGroups, 'successes')),
        ];
    }

    /**
     * Ventaja / desventaja: el primer 1d20 sin quedarse/descartar pasa a ser
     * 2d20kh1 / 2d20kl1. Si no hay ninguno, la tirada no cambia.
     */
    private function applyMode(array $node, string $mode): array
    {
        $done = false;

        $walk = function (array $n) use (&$walk, &$done, $mode): array {
            if ($done) {
                return $n;
            }

            if ($n['n'] === 'dice') {
                $hasKeep = (bool) array_filter($n['mods'], fn ($m) => in_array($m['m'], ['kh', 'kl', 'dh', 'dl'], true));

                if ($n['sides'] === 20 && $n['count'] === 1 && ! $hasKeep) {
                    $done = true;
                    $keep = $mode === 'advantage' ? 'kh' : 'kl';
                    $n['count'] = 2;
                    $n['mods'][] = ['m' => $keep, 'n' => 1];
                    $n['text'] = preg_replace('/^1?d20/', '2d20'.$keep.'1', $n['text'], 1);
                }

                return $n;
            }

            return match ($n['n']) {
                'bin' => [...$n, 'l' => $walk($n['l']), 'r' => $walk($n['r'])],
                'neg' => [...$n, 'x' => $walk($n['x'])],
                default => $n,
            };
        };

        return $walk($node);
    }

    private function evaluate(array $node): int
    {
        return match ($node['n']) {
            'num' => $node['v'],
            'neg' => -$this->evaluate($node['x']),
            'dice' => $this->rollGroup($node),
            'bin' => $this->binary($node['op'], $this->evaluate($node['l']), $this->evaluate($node['r'])),
        };
    }

    private function binary(string $op, int $l, int $r): int
    {
        return match ($op) {
            '+' => $l + $r,
            '-' => $l - $r,
            '*' => $l * $r,
            // Se redondea hacia abajo, como en la mesa: 7/2 = 3, −7/2 = −4.
            '/' => $r === 0 ? throw new DiceException('División por cero.') : (int) floor($l / $r),
        };
    }

    // ------------------------------------------------------------- grupos

    private function rollGroup(array $node): int
    {
        $sides = $node['sides'];
        $fudge = $sides === 'F';
        [$min, $max] = $fudge ? [-1, 1] : [1, $sides];

        $mods = $node['mods'];
        $reroll = $this->firstMod($mods, 'reroll');
        $explode = $this->firstMod($mods, 'explode');
        $success = $this->firstMod($mods, 'success');
        $critCmp = $this->firstMod($mods, 'crit')['cmp'] ?? null;
        $fumbleCmp = $this->firstMod($mods, 'fumble')['cmp'] ?? null;

        // Por defecto, en un d20 el 20 natural es crítico y el 1 pifia.
        if ($sides === 20) {
            $critCmp ??= ['op' => '>=', 'v' => 20];
            $fumbleCmp ??= ['op' => '<=', 'v' => 1];
        }

        $explodeCmp = $explode ? ($explode['cmp'] ?? ['op' => '>=', 'v' => $max]) : null;

        if ($reroll && $this->coversAll($reroll['cmp'], $min, $max)) {
            throw new DiceException("«{$node['text']}» relanzaría todos los resultados posibles.");
        }

        if ($explodeCmp && $this->coversAll($explodeCmp, $min, $max)) {
            throw new DiceException("«{$node['text']}» explotaría siempre.");
        }

        $dice = [];

        for ($i = 0; $i < $node['count']; $i++) {
            $dice[] = $this->rollDie($min, $max, $reroll);

            // Cada explosión es un dado nuevo, que a su vez puede explotar.
            $last = end($dice);
            for ($chain = 0; $explodeCmp && $this->matches($last['value'], $explodeCmp) && $chain < self::MAX_CHAIN; $chain++) {
                $last = $this->rollDie($min, $max, $reroll) + ['exploded' => true];
                $dice[] = $last;
            }
        }

        $this->keepAndDrop($dice, $mods);

        $value = 0;
        $successes = null;
        $crit = false;
        $fumble = false;

        foreach ($dice as &$die) {
            if (! $die['kept']) {
                continue;
            }

            if ($success) {
                $die['success'] = $this->matches($die['value'], $success['cmp']);
                $successes = ($successes ?? 0) + ($die['success'] ? 1 : 0);
            } else {
                $value += $die['value'];
            }

            $crit = $crit || ($critCmp && $this->matches($die['value'], $critCmp));
            $fumble = $fumble || ($fumbleCmp && $this->matches($die['value'], $fumbleCmp));
        }
        unset($die);

        if ($success) {
            $value = $successes;
        }

        $this->groups[] = [
            'notation' => $node['text'],
            'sides' => $sides,
            'value' => $value,
            'dice' => $dice,
            'crit' => $crit,
            'fumble' => $fumble,
            'successes' => $successes,
        ];

        return $value;
    }

    /** Un dado, relanzado si toca. Guarda lo que salió antes de relanzarlo. */
    private function rollDie(int $min, int $max, ?array $reroll): array
    {
        $value = $this->die($min, $max);
        $previous = [];

        if ($reroll) {
            $limit = $reroll['once'] ? 1 : self::MAX_CHAIN;

            while ($limit-- > 0 && $this->matches($value, $reroll['cmp'])) {
                $previous[] = $value;
                $value = $this->die($min, $max);
            }
        }

        return array_filter(['value' => $value, 'kept' => true, 'rerolled' => $previous], fn ($v) => $v !== []);
    }

    private function die(int $min, int $max): int
    {
        if (++$this->rolled > self::MAX_DICE) {
            throw new DiceException('Demasiados dados en una sola tirada.');
        }

        return $this->rng->int($min, $max);
    }

    /** Quedarse con / descartar los más altos o bajos (en el orden en que se escriben). */
    private function keepAndDrop(array &$dice, array $mods): void
    {
        foreach ($mods as $mod) {
            if (! in_array($mod['m'], ['kh', 'kl', 'dh', 'dl'], true)) {
                continue;
            }

            $kept = array_keys(array_filter($dice, fn ($d) => $d['kept']));

            // Ordenados por valor; a igualdad, por posición (determinista).
            usort($kept, fn ($a, $b) => [$dice[$a]['value'], $a] <=> [$dice[$b]['value'], $b]);

            $n = min($mod['n'], count($kept));
            $drop = match ($mod['m']) {
                'kh' => array_slice($kept, 0, count($kept) - $n),
                'kl' => array_slice($kept, $n),
                'dh' => array_slice($kept, count($kept) - $n),
                'dl' => array_slice($kept, 0, $n),
            };

            foreach ($drop as $index) {
                $dice[$index]['kept'] = false;
            }
        }
    }

    private function firstMod(array $mods, string $kind): ?array
    {
        foreach ($mods as $mod) {
            if ($mod['m'] === $kind) {
                return $mod;
            }
        }

        return null;
    }

    private function matches(int $value, array $cmp): bool
    {
        return match ($cmp['op']) {
            '>=' => $value >= $cmp['v'],
            '>' => $value > $cmp['v'],
            '<=' => $value <= $cmp['v'],
            '<' => $value < $cmp['v'],
            '=' => $value === $cmp['v'],
        };
    }

    /** ¿Cumple la comparación cualquier cara del dado? (bucle infinito) */
    private function coversAll(array $cmp, int $min, int $max): bool
    {
        for ($v = $min; $v <= $max; $v++) {
            if (! $this->matches($v, $cmp)) {
                return false;
            }
        }

        return true;
    }
}
