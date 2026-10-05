<?php

namespace App\Domain\Dice;

use App\Models\Campaign;
use App\Models\DiceRoll;
use App\Models\Sheet;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Hacer una tirada y dejarla escrita (§7).
 *
 *   - Se tira en el servidor (DiceRoller, random_int) y se guarda SIEMPRE en
 *     dice_rolls: con mesa va a su registro compartido; sin mesa, al historial
 *     de quien tira.
 *   - Límite: 30 tiradas por minuto y usuario.
 *   - En una mesa tiran el DJ y los jugadores, no los espectadores. Solo el
 *     DJ puede tirar en secreto (is_private): la ven él y nadie más.
 */
final class RollDice
{
    public const PER_MINUTE = 30;

    public function __construct(private DiceRoller $roller = new DiceRoller) {}

    public function __invoke(
        User $user,
        string $expression,
        ?string $label = null,
        string $mode = 'normal',
        ?Sheet $sheet = null,
        ?Campaign $campaign = null,
        bool $private = false,
    ): DiceRoll {
        if ($campaign) {
            $role = $campaign->roleOf($user);

            if (! in_array($role, ['gm', 'player'], true)) {
                throw new DiceException('No puedes tirar en esta mesa.');
            }

            $private = $private && $role === 'gm';

            // Una hoja que no está en la mesa no tira «en nombre» de la mesa.
            if ($sheet && ! $campaign->sheets()->whereKey($sheet->id)->exists()) {
                $sheet = null;
            }
        } else {
            $private = false;
        }

        $key = 'dice:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw new DiceException('Demasiadas tiradas seguidas: espera '.RateLimiter::availableIn($key).' s.');
        }

        $result = $this->roller->roll($expression, $mode);
        RateLimiter::hit($key, 60);

        return DiceRoll::create([
            'campaign_id' => $campaign?->id,
            'sheet_id' => $sheet?->id,
            'user_id' => $user->id,
            'label' => $label === null ? null : mb_substr(trim($label), 0, 180),
            'expression' => mb_substr($expression, 0, 120),
            'result' => $result,
            'mode' => $result['mode'],
            'is_private' => $private,
            'created_at' => now(),
        ]);
    }
}
