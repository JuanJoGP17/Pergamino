<?php

namespace App\Domain\Campaign;

use App\Domain\Dice\RollDice;
use App\Domain\Sheet\SheetView;
use App\Models\Campaign;
use App\Models\Sheet;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Iniciativa de una mesa (§8): lista ordenada, turno actual y ronda.
 *
 * Vive en campaigns.settings['initiative'] — es un estado pequeño, de una
 * sola mesa, que se lee entero cada vez — así:
 *
 *   { "round": 2, "turn": 1,
 *     "entries": [ {"id": "a1b2", "name": "Eldra", "value": 17, "sheet_id": 4}, … ] }
 *
 * La lista se ordena de mayor a menor en cada cambio (a igualdad, el que
 * entró antes). Solo el DJ la toca; los demás la ven.
 */
final class Initiative
{
    public const MAX_ENTRIES = 40;

    public static function of(Campaign $campaign): array
    {
        $state = $campaign->settings['initiative'] ?? [];

        return [
            'round' => max(1, (int) ($state['round'] ?? 1)),
            'turn' => max(0, (int) ($state['turn'] ?? 0)),
            'entries' => array_values(array_filter($state['entries'] ?? [], 'is_array')),
        ];
    }

    public function add(Campaign $campaign, User $gm, string $name, int|float|string|null $value, ?int $sheetId = null): void
    {
        $this->change($campaign, $gm, function (array $state) use ($name, $value, $sheetId) {
            $name = trim($name);

            if ($name === '') {
                throw new CampaignException('Ponle un nombre.');
            }

            if (count($state['entries']) >= self::MAX_ENTRIES) {
                throw new CampaignException('La iniciativa admite como mucho '.self::MAX_ENTRIES.' participantes.');
            }

            $state['entries'][] = [
                'id' => Str::random(8),
                'name' => mb_substr($name, 0, 60),
                'value' => is_numeric($value) ? (int) $value : 0,
                'sheet_id' => $sheetId,
            ];

            return $state;
        });
    }

    /**
     * Añade las hojas de la mesa que aún no están. Si la hoja tiene un campo
     * «iniciativa» con tirada, se tira de verdad (y queda en el registro);
     * si no, entra con 0 para que el DJ ponga el valor.
     */
    public function addSheets(Campaign $campaign, User $gm): int
    {
        $state = self::of($campaign);
        $already = array_filter(array_column($state['entries'], 'sheet_id'));
        $added = 0;

        foreach ($campaign->sheets()->with('version')->get() as $sheet) {
            if (in_array($sheet->id, $already, true)) {
                continue;
            }

            $this->add($campaign->fresh(), $gm, $sheet->name, $this->rollFor($campaign, $gm, $sheet), $sheet->id);
            $added++;
        }

        return $added;
    }

    public function setValue(Campaign $campaign, User $gm, string $id, int|string|null $value): void
    {
        $this->change($campaign, $gm, function (array $state) use ($id, $value) {
            foreach ($state['entries'] as $i => $entry) {
                if ($entry['id'] === $id) {
                    $state['entries'][$i]['value'] = is_numeric($value) ? (int) $value : 0;
                }
            }

            return $state;
        });
    }

    public function remove(Campaign $campaign, User $gm, string $id): void
    {
        $this->change($campaign, $gm, function (array $state) use ($id) {
            $index = array_search($id, array_column($state['entries'], 'id'), true);

            if ($index !== false) {
                array_splice($state['entries'], $index, 1);

                // Si se va alguien de antes del turno, el turno no debe saltar a otro.
                if ($index < $state['turn']) {
                    $state['turn']--;
                }
            }

            return $state;
        });
    }

    /** Siguiente turno; tras el último, ronda nueva. */
    public function next(Campaign $campaign, User $gm): void
    {
        $this->change($campaign, $gm, function (array $state) {
            if ($state['entries'] === []) {
                return $state;
            }

            $state['turn']++;

            if ($state['turn'] >= count($state['entries'])) {
                $state['turn'] = 0;
                $state['round']++;
            }

            return $state;
        }, sort: false);
    }

    public function previous(Campaign $campaign, User $gm): void
    {
        $this->change($campaign, $gm, function (array $state) {
            if ($state['entries'] === [] || ($state['turn'] === 0 && $state['round'] === 1)) {
                return $state;
            }

            $state['turn']--;

            if ($state['turn'] < 0) {
                $state['turn'] = count($state['entries']) - 1;
                $state['round']--;
            }

            return $state;
        }, sort: false);
    }

    /** Fin del combate: lista vacía, ronda 1. */
    public function reset(Campaign $campaign, User $gm): void
    {
        $this->change($campaign, $gm, fn () => ['round' => 1, 'turn' => 0, 'entries' => []]);
    }

    // ------------------------------------------------------------ internos

    private function change(Campaign $campaign, User $gm, callable $mutation, bool $sort = true): void
    {
        if ($campaign->gm_id !== $gm->id) {
            throw new CampaignException('La iniciativa la lleva el DJ.');
        }

        $state = $mutation(self::of($campaign));

        if ($sort) {
            // De mayor a menor, estable: a igualdad, el que entró antes.
            // Antes de empezar (ronda 1, primer turno) el turno es siempre el
            // de arriba; ya empezado, sigue a quien lo tenía.
            $started = $state['round'] > 1 || $state['turn'] > 0;
            $current = $started ? ($state['entries'][$state['turn']]['id'] ?? null) : null;
            $order = array_keys($state['entries']);
            usort($order, fn ($a, $b) => [$state['entries'][$b]['value'], $a] <=> [$state['entries'][$a]['value'], $b]);
            $state['entries'] = array_values(array_map(fn ($i) => $state['entries'][$i], $order));

            // El turno sigue con quien lo tenía, aunque haya cambiado de sitio.
            $index = $current === null ? false : array_search($current, array_column($state['entries'], 'id'), true);
            $state['turn'] = $index === false ? 0 : $index;
        }

        $state['turn'] = min($state['turn'], max(0, count($state['entries']) - 1));

        $settings = $campaign->settings ?? [];
        $settings['initiative'] = $state;
        $campaign->update(['settings' => $settings]);
    }

    private function rollFor(Campaign $campaign, User $gm, Sheet $sheet): int
    {
        $field = $sheet->schema()->field('iniciativa');

        if (! $field || empty($field['roll'])) {
            return 0;
        }

        $expression = (new SheetView($sheet->schema(), $sheet->data ?? [], $sheet->computed ?? []))->rolls()['iniciativa'] ?? null;

        if (! $expression) {
            return 0;
        }

        return app(RollDice::class)($gm, $expression, "{$sheet->name} · Iniciativa", sheet: $sheet, campaign: $campaign)->total();
    }
}
