<?php

namespace App\Livewire\Campaign;

use App\Domain\Dice\RollView;
use App\Models\Campaign;
use App\Models\DiceRoll;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Registro de tiradas de la mesa (§8), compartido y con polling. Cada poll
 * es una consulta por (campaign_id, id) — el índice que ya tiene la tabla —
 * con las últimas tiradas que quien mira puede ver: las secretas del DJ solo
 * las ve él.
 */
class RollLog extends Component
{
    public const SHOWN = 40;

    #[Locked]
    public string $campaignUuid;

    /** Filtrar por quien tira ('' = todos). */
    public string $who = '';

    /** Solo quien está en la mesa. El uuid queda bloqueado (Locked) tras esto. */
    public function mount(string $campaignUuid): void
    {
        $this->campaignUuid = $campaignUuid;
        $campaign = Campaign::where('uuid', $campaignUuid)->firstOrFail();

        abort_unless(auth()->user()?->can('view', $campaign), 403);
    }

    /** Las tiradas recién hechas en esta página aparecen sin esperar al poll. */
    #[On('roll-logged')]
    public function refresh(): void {}

    public function render()
    {
        $campaign = Campaign::where('uuid', $this->campaignUuid)->firstOrFail();

        // Si echan a alguien con la mesa abierta, el siguiente poll ya no le enseña nada.
        if (! auth()->user()?->can('view', $campaign)) {
            return '<div class="text-sm text-[var(--pg-muted)]">Ya no estás en esta mesa.</div>';
        }
        $user = auth()->user();

        $rolls = DiceRoll::visibleTo($user, $campaign)
            ->when(is_numeric($this->who), fn ($q) => $q->where('user_id', (int) $this->who))
            ->with('user:id,name')
            ->orderByDesc('id')
            ->take(self::SHOWN)
            ->get();

        return view('livewire.campaign.roll-log', [
            'rolls' => $rolls->map(fn (DiceRoll $r) => RollView::from($r)),
            'people' => $campaign->users()->get(['users.id', 'users.name']),
        ]);
    }
}
