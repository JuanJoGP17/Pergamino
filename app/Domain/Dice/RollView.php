<?php

namespace App\Domain\Dice;

use App\Models\DiceRoll;

/**
 * Una tirada lista para pintar (bandeja de dados, registro de la mesa).
 * Sirve igual para una guardada (DiceRoll) que para una de la vista previa
 * del constructor, que no se guarda.
 */
final class RollView
{
    public static function from(DiceRoll $roll): array
    {
        $roll->loadMissing('user:id,name');

        return self::make($roll->result, $roll->label, $roll->is_private, $roll->user?->displayName(), $roll->id, $roll->created_at?->format('H:i'));
    }

    public static function make(array $result, ?string $label, bool $private = false, ?string $who = null, ?int $id = null, ?string $at = null): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'who' => $who,
            'at' => $at ?? now()->format('H:i'),
            'private' => $private,
            'expression' => $result['expression'] ?? '',
            'mode' => $result['mode'] ?? 'normal',
            'total' => $result['total'] ?? 0,
            'crit' => (bool) ($result['crit'] ?? false),
            'fumble' => (bool) ($result['fumble'] ?? false),
            'successes' => $result['successes'] ?? null,
            'groups' => array_map(fn (array $g) => [
                'notation' => $g['notation'],
                'sides' => $g['sides'],
                'value' => $g['value'],
                'dice' => array_map(fn (array $d) => [
                    'value' => $d['value'],
                    'kept' => $d['kept'] ?? true,
                    'exploded' => $d['exploded'] ?? false,
                    'rerolled' => $d['rerolled'] ?? [],
                    'success' => $d['success'] ?? null,
                ], $g['dice'] ?? []),
            ], $result['groups'] ?? []),
        ];
    }
}
