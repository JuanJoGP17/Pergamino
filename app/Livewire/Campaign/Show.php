<?php

namespace App\Livewire\Campaign;

use App\Domain\Campaign\CampaignException;
use App\Domain\Campaign\Campaigns;
use App\Domain\Sheet\CreateSheet;
use App\Domain\Theme\Presets;
use App\Models\Campaign;
use App\Models\Sheet;
use App\Models\Template;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Vista de mesa (§8).
 *
 *   cabecera (código e invitación)
 *   GRUPO (tarjetas) · INICIATIVA · REGISTRO DE TIRADAS   ← cada uno, con polling
 *   notas · personas · ajustes del DJ
 *
 * Lo que cambia mientras se juega (tarjetas, iniciativa, tiradas) son
 * componentes hijos con su propio wire:poll: el poll repinta solo eso, no la
 * mesa entera (es lo que el plan pedía de los islands). Esta vista es lo que
 * cambia poco: notas, personas y ajustes.
 */
#[Layout('components.layouts.app', ['wide' => true])]
class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $campaignUuid;

    /** Añadir una hoja mía a la mesa. */
    public string $sheetToAdd = '';

    public string $shareLevel = 'summary';

    /** Nota que se está escribiendo (null = nueva). */
    public ?int $noteId = null;

    public string $noteTitle = '';

    public string $noteBody = '';

    public bool $noteGmOnly = false;

    /** Ajustes del DJ. */
    public array $settings = [];

    public ?array $notice = null;

    public function mount(Campaign $campaign): void
    {
        $this->authorize('view', $campaign);
        $this->campaignUuid = $campaign->uuid;
        $this->loadSettings($campaign);

        // Dentro de una mesa, la bandeja de dados tira en ella por defecto.
        $this->dispatch('campaign-opened', uuid: $campaign->uuid);
    }

    public function campaign(): Campaign
    {
        return Campaign::where('uuid', $this->campaignUuid)->firstOrFail();
    }

    // --------------------------------------------------------------- hojas

    public function addSheet(): void
    {
        $this->run(function (Campaigns $campaigns, Campaign $campaign) {
            $sheet = Sheet::where('owner_id', auth()->id())->where('uuid', Str::isUuid($this->sheetToAdd) ? $this->sheetToAdd : null)->first()
                ?? throw new CampaignException('Elige una de tus hojas.');
            $campaigns->addSheet($campaign, auth()->user(), $sheet, $this->shareLevel);
            $this->sheetToAdd = '';
            $this->dispatch('party-changed');
        });
    }

    /** Plantilla sugerida (§8): crear la hoja ya dentro de la mesa, de un clic. */
    public function createSheet()
    {
        $campaign = $this->campaign();
        $this->authorize('view', $campaign);
        $template = $campaign->defaultTemplate;

        if (! $template || ! auth()->user()->can('view', $template) || ! in_array($campaign->roleOf(auth()->user()), ['gm', 'player'], true)) {
            return null;
        }

        $sheet = app(CreateSheet::class)($template, auth()->user());
        app(Campaigns::class)->addSheet($campaign, auth()->user(), $sheet, 'summary');

        return $this->redirect(route('sheets.edit', $sheet), navigate: true);
    }

    // ------------------------------------------------------------- personas

    public function setRole(int $userId, string $role): void
    {
        $this->run(fn (Campaigns $c, Campaign $campaign) => $c->setRole($campaign, auth()->user(), $userId, $role));
    }

    public function removeMember(int $userId)
    {
        $leaving = $userId === auth()->id();
        $this->run(fn (Campaigns $c, Campaign $campaign) => $c->removeMember($campaign, auth()->user(), $userId));

        if ($leaving && $this->notice === null) {
            return $this->redirect(route('campaigns.index'), navigate: true);
        }

        $this->dispatch('party-changed');

        return null;
    }

    public function regenerateCode(): void
    {
        $this->run(fn (Campaigns $c, Campaign $campaign) => $c->regenerateCode($campaign, auth()->user()));
    }

    // ---------------------------------------------------------------- notas

    public function editNote(int $id): void
    {
        $note = $this->campaign()->notes()->find($id);

        if ($note && ($note->author_id === auth()->id() || $this->campaign()->gm_id === auth()->id())) {
            [$this->noteId, $this->noteTitle, $this->noteBody, $this->noteGmOnly] = [$note->id, (string) $note->title, (string) $note->body, $note->is_gm_only];
        }
    }

    public function saveNote(): void
    {
        $this->run(function (Campaigns $c, Campaign $campaign) {
            if (trim($this->noteTitle.$this->noteBody) === '') {
                throw new CampaignException('La nota está vacía.');
            }

            $c->saveNote($campaign, auth()->user(), $this->noteId, $this->noteTitle, $this->noteBody, $this->noteGmOnly);
            $this->cancelNote();
        });
    }

    public function cancelNote(): void
    {
        $this->reset('noteId', 'noteTitle', 'noteBody', 'noteGmOnly');
    }

    public function deleteNote(int $id): void
    {
        $this->run(fn (Campaigns $c, Campaign $campaign) => $c->deleteNote($campaign, auth()->user(), $id));
    }

    // -------------------------------------------------------------- ajustes

    public function updatedSettings(): void
    {
        $this->run(function (Campaigns $c, Campaign $campaign) {
            $c->updateSettings($campaign, auth()->user(), $this->settings);
            $this->notice = ['type' => 'ok', 'text' => 'Ajustes guardados.'];
        });
        $this->loadSettings($this->campaign());
    }

    // --------------------------------------------------------------- render

    public function render()
    {
        $campaign = $this->campaign()->load(['gm:id,name', 'defaultTemplate:id,name,current_version_id']);
        $user = auth()->user();
        $role = $campaign->roleOf($user);
        $isGm = $role === 'gm';

        return view('livewire.campaign.show', [
            'campaign' => $campaign,
            'role' => $role,
            'isGm' => $isGm,
            'members' => $campaign->members()->with('user:id,name')->orderByRaw("CASE role WHEN 'gm' THEN 0 WHEN 'player' THEN 1 ELSE 2 END")->get(),
            'notes' => $campaign->notes()->with('author:id,name')
                ->when(! $isGm, fn ($q) => $q->where('is_gm_only', false))
                ->latest('updated_at')->get(),
            'mySheets' => in_array($role, ['gm', 'player'], true)
                ? Sheet::where('owner_id', $user->id)->whereNotIn('id', $campaign->sheets()->pluck('sheets.id'))->orderBy('name')->get(['id', 'uuid', 'name'])
                : collect(),
            'templates' => $isGm ? Template::visibleTo($user)->whereNotNull('current_version_id')->orderBy('name')->get(['id', 'name']) : collect(),
            'presets' => Presets::options(),
        ]);
    }

    // ------------------------------------------------------------- internos

    private function run(callable $operation): void
    {
        $campaign = $this->campaign();
        $this->authorize('view', $campaign);
        $this->notice = null;

        try {
            $operation(app(Campaigns::class), $campaign);
        } catch (CampaignException $e) {
            $this->notice = ['type' => 'error', 'text' => $e->getMessage()];
        }
    }

    private function loadSettings(Campaign $campaign): void
    {
        $this->settings = [
            'name' => $campaign->name,
            'description' => $campaign->description,
            'default_template_id' => $campaign->default_template_id,
            'gm_can_edit_sheets' => (bool) ($campaign->settings['gm_can_edit_sheets'] ?? true),
            'theme_preset' => $campaign->theme_override['preset'] ?? '',
        ];
    }
}
