<?php

namespace App\Livewire;

use App\Domain\Dice\DiceException;
use App\Domain\Dice\RollDice;
use App\Domain\Dice\RollView;
use App\Models\Campaign;
use App\Models\DiceRoll;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Bandeja de dados global (§7, Fase 6): un botón flotante en todas las
 * páginas para tirar a mano, con ventaja o desventaja, en una mesa o sin
 * ella, y el historial propio.
 *
 * Las tiradas de los campos de una hoja también acaban aquí: el editor
 * emite `dice-rolled` con la tirada ya hecha y la bandeja la enseña.
 */
class DiceTray extends Component
{
    public bool $open = false;

    public string $expression = '1d20';

    public string $mode = 'normal';

    /** uuid de la mesa donde tirar, o '' para tirar sin mesa. */
    public string $campaign = '';

    public bool $private = false;

    /** La última tirada, para enseñarla en grande. */
    public ?array $last = null;

    public ?string $error = null;

    public function roll(): void
    {
        $this->error = null;
        $campaign = $this->selectedCampaign();

        try {
            $roll = app(RollDice::class)(auth()->user(), $this->expression, null, $this->mode, campaign: $campaign, private: $this->private);
        } catch (DiceException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->showRoll(RollView::from($roll));
    }

    /** Al entrar en la vista de una mesa, tirar en ella (si se puede). */
    #[On('campaign-opened')]
    public function useCampaign(string $uuid): void
    {
        $user = auth()->user();
        $campaign = Str::isUuid($uuid) ? Campaign::where('uuid', $uuid)->first() : null;

        if ($campaign && in_array($campaign->roleOf($user), ['gm', 'player'], true)) {
            $this->campaign = $uuid;
            $this->private = false;
        }
    }

    #[On('dice-rolled')]
    public function showRoll(array $roll): void
    {
        $this->last = $roll;
        $this->open = true;
        $this->error = null;
        $this->dispatch('roll-logged');
    }

    public function render()
    {
        $user = auth()->user();
        $campaigns = $user->campaigns()->wherePivotIn('role', ['gm', 'player'])->orderBy('name')->get(['campaigns.id', 'uuid', 'name', 'gm_id']);
        $selected = $campaigns->firstWhere('uuid', $this->campaign);

        return view('livewire.dice-tray', [
            'campaigns' => $campaigns,
            'isGm' => $selected && $selected->gm_id === $user->id,
            'history' => $this->open
                ? DiceRoll::where('user_id', $user->id)->latest('id')->take(10)->get()->map(fn ($r) => RollView::from($r))
                : collect(),
        ]);
    }

    private function selectedCampaign(): ?Campaign
    {
        if ($this->campaign === '' || ! Str::isUuid($this->campaign)) {
            return null;
        }

        return Campaign::where('uuid', $this->campaign)->first();
    }
}
