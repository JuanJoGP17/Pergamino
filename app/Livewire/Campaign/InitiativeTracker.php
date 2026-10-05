<?php

namespace App\Livewire\Campaign;

use App\Domain\Campaign\CampaignException;
use App\Domain\Campaign\Initiative;
use App\Domain\Dice\DiceException;
use App\Models\Campaign;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Iniciativa (§8): lista ordenada, turno y ronda. El DJ la lleva; los demás
 * la ven moverse (polling).
 */
class InitiativeTracker extends Component
{
    #[Locked]
    public string $campaignUuid;

    public string $name = '';

    public string $value = '';

    public ?string $error = null;

    /** Solo quien está en la mesa. El uuid queda bloqueado (Locked) tras esto. */
    public function mount(string $campaignUuid): void
    {
        $this->campaignUuid = $campaignUuid;
        $campaign = Campaign::where('uuid', $campaignUuid)->firstOrFail();

        abort_unless(auth()->user()?->can('view', $campaign), 403);
    }

    public function add(): void
    {
        $this->run(function (Initiative $i, Campaign $c) {
            $i->add($c, auth()->user(), $this->name, $this->value);
            $this->reset('name', 'value');
        });
    }

    public function addSheets(): void
    {
        $this->run(function (Initiative $i, Campaign $c) {
            $i->addSheets($c, auth()->user());
            $this->dispatch('roll-logged');
        });
    }

    public function setValue(string $id, $value): void
    {
        $this->run(fn (Initiative $i, Campaign $c) => $i->setValue($c, auth()->user(), $id, is_scalar($value) ? (string) $value : null));
    }

    public function remove(string $id): void
    {
        $this->run(fn (Initiative $i, Campaign $c) => $i->remove($c, auth()->user(), $id));
    }

    public function next(): void
    {
        $this->run(fn (Initiative $i, Campaign $c) => $i->next($c, auth()->user()));
    }

    public function previous(): void
    {
        $this->run(fn (Initiative $i, Campaign $c) => $i->previous($c, auth()->user()));
    }

    public function resetCombat(): void
    {
        $this->run(fn (Initiative $i, Campaign $c) => $i->reset($c, auth()->user()));
    }

    public function render()
    {
        $campaign = $this->campaign();

        // Si echan a alguien con la mesa abierta, el siguiente poll ya no le enseña nada.
        if (! auth()->user()?->can('view', $campaign)) {
            return '<div class="text-sm text-[var(--pg-muted)]">Ya no estás en esta mesa.</div>';
        }

        return view('livewire.campaign.initiative', [
            'state' => Initiative::of($campaign),
            'isGm' => $campaign->gm_id === auth()->id(),
        ]);
    }

    private function campaign(): Campaign
    {
        return Campaign::where('uuid', $this->campaignUuid)->firstOrFail();
    }

    private function run(callable $operation): void
    {
        $this->error = null;

        try {
            $operation(app(Initiative::class), $this->campaign());
        } catch (CampaignException|DiceException $e) {
            $this->error = $e->getMessage();
        }
    }
}
