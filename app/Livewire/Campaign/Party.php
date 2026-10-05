<?php

namespace App\Livewire\Campaign;

use App\Domain\Campaign\CampaignException;
use App\Domain\Campaign\Campaigns;
use App\Domain\Sheet\SheetPrinter;
use App\Models\Campaign;
use App\Models\Sheet;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Tarjetas resumen del grupo (§8): cada hoja de la mesa con sus campos
 * marcados «en el resumen» (PV, CA, iniciativa…). Se repinta sola cada pocos
 * segundos: si un jugador recibe daño, el DJ lo ve sin recargar.
 *
 * Quién ve qué: el DJ, todas; cada uno, las suyas; el resto, las que no
 * están ocultas. Abrir la hoja entera depende de la policy (nivel «full»).
 */
class Party extends Component
{
    #[Locked]
    public string $campaignUuid;

    public ?string $error = null;

    /** Solo quien está en la mesa. El uuid queda bloqueado (Locked) tras esto. */
    public function mount(string $campaignUuid): void
    {
        $this->campaignUuid = $campaignUuid;
        $campaign = Campaign::where('uuid', $campaignUuid)->firstOrFail();

        abort_unless(auth()->user()?->can('view', $campaign), 403);
    }

    #[On('party-changed')]
    public function refresh(): void {}

    public function setShareLevel(string $sheetUuid, string $level): void
    {
        $this->run(fn (Campaigns $c, Campaign $campaign, Sheet $sheet) => $c->setShareLevel($campaign, auth()->user(), $sheet, $level), $sheetUuid);
    }

    public function removeSheet(string $sheetUuid): void
    {
        $this->run(fn (Campaigns $c, Campaign $campaign, Sheet $sheet) => $c->removeSheet($campaign, auth()->user(), $sheet), $sheetUuid);
    }

    public function render()
    {
        $campaign = Campaign::where('uuid', $this->campaignUuid)->firstOrFail();

        // Si echan a alguien con la mesa abierta, el siguiente poll ya no le enseña nada.
        if (! auth()->user()?->can('view', $campaign)) {
            return '<div class="text-sm text-[var(--pg-muted)]">Ya no estás en esta mesa.</div>';
        }
        $user = auth()->user();
        $isGm = $campaign->gm_id === $user->id;

        $sheets = $campaign->sheets()->with(['owner:id,name', 'version', 'template:id,name'])->get()
            ->filter(fn (Sheet $s) => $isGm || $s->owner_id === $user->id || $s->pivot->share_level !== 'hidden');

        return view('livewire.campaign.party', [
            'isGm' => $isGm,
            'cards' => $sheets->map(fn (Sheet $s) => [
                'sheet' => $s,
                'summary' => (new SheetPrinter($s))->summary(),
                'canOpen' => $user->can('view', $s),
                'canManage' => $isGm || $s->owner_id === $user->id,
            ]),
        ]);
    }

    private function run(callable $operation, string $sheetUuid): void
    {
        $campaign = Campaign::where('uuid', $this->campaignUuid)->firstOrFail();
        $this->error = null;

        if (! auth()->user()->can('view', $campaign)) {
            abort(403);
        }

        $sheet = Str::isUuid($sheetUuid) ? Sheet::where('uuid', $sheetUuid)->first() : null;

        try {
            $operation(app(Campaigns::class), $campaign, $sheet ?? throw new CampaignException('Esa hoja no existe.'));
        } catch (CampaignException $e) {
            $this->error = $e->getMessage();
        }
    }
}
