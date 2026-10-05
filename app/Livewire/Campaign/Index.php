<?php

namespace App\Livewire\Campaign;

use App\Domain\Campaign\CampaignException;
use App\Domain\Campaign\Campaigns;
use App\Models\Template;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Mis mesas: las que dirijo y en las que juego. Crear una o unirse con el
 * código que pasa el DJ (§8).
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    public string $name = '';

    public string $description = '';

    public string $templateId = '';

    public string $code = '';

    public function create()
    {
        $this->validate(['name' => ['required', 'string', 'max:160']], [], ['name' => 'nombre']);

        $campaign = app(Campaigns::class)->create(auth()->user(), $this->name, $this->description,
            is_numeric($this->templateId) ? (int) $this->templateId : null);

        return $this->redirect(route('campaigns.show', $campaign), navigate: true);
    }

    public function join()
    {
        try {
            $campaign = app(Campaigns::class)->join(auth()->user(), $this->code);
        } catch (CampaignException $e) {
            $this->addError('code', $e->getMessage());

            return null;
        }

        return $this->redirect(route('campaigns.show', $campaign), navigate: true);
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.campaign.index', [
            'campaigns' => $user->campaigns()
                ->with('gm:id,name')
                ->withCount(['members', 'sheets'])
                ->orderByDesc('campaigns.updated_at')
                ->get(),
            'templates' => Template::visibleTo($user)->whereNotNull('current_version_id')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
