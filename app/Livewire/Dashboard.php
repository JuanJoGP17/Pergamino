<?php

namespace App\Livewire;

use App\Domain\Sheet\CreateSheet;
use App\Models\Sheet;
use App\Models\Template;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    use AuthorizesRequests;

    public function createSheetFrom(string $templateUuid)
    {
        $template = Template::where('uuid', $templateUuid)->firstOrFail();
        $this->authorize('view', $template);

        $sheet = app(CreateSheet::class)($template, auth()->user());

        return $this->redirect(route('sheets.edit', $sheet), navigate: true);
    }

    public function deleteSheet(string $uuid): void
    {
        $sheet = Sheet::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $sheet);
        $sheet->delete();
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.dashboard', [
            'sheets' => Sheet::where('owner_id', $user->id)
                ->with('template:id,name,game_line')
                ->select(['id', 'uuid', 'owner_id', 'template_id', 'name', 'portrait_path', 'updated_at'])
                ->latest('updated_at')
                ->take(24)
                ->get(),
            'templates' => Template::visibleTo($user)
                ->whereNotNull('current_version_id')
                ->withCount('sheets')
                ->orderByDesc('is_official')
                ->orderBy('name')
                ->take(12)
                ->get(),
        ]);
    }
}
