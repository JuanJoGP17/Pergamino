<?php

namespace App\Livewire\Template;

use App\Domain\Builder\TemplateEditor;
use App\Models\Template;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Mis plantillas y las públicas. El catálogo completo, con búsqueda y filtros,
 * llega en la Fase 7.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public function create()
    {
        $this->authorize('create', Template::class);
        $this->validate(['name' => ['required', 'string', 'max:120']], [], ['name' => 'nombre']);

        $template = app(TemplateEditor::class)->create(auth()->user(), $this->name);

        return $this->redirect(route('templates.builder', $template), navigate: true);
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.template.index', [
            'mine' => Template::where('owner_id', $user->id)
                ->with('currentVersion:id,version,published_at')
                ->withCount('sheets')
                ->orderByDesc('updated_at')
                ->get(),
            'public' => Template::public()
                ->where('owner_id', '!=', $user->id)
                ->whereNotNull('current_version_id')
                ->withCount('sheets')
                ->orderByDesc('is_official')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
