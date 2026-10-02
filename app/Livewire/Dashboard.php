<?php

namespace App\Livewire;

use App\Domain\Sheet\CreateSheet;
use App\Domain\Transfer\SheetTransfer;
use App\Domain\Transfer\TransferException;
use App\Models\Sheet;
use App\Models\Template;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;

#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    use AuthorizesRequests, WithFileUploads;

    /** Importar una hoja exportada (§6.4): el archivo y, si se quiere, otra plantilla. */
    public $sheetFile = null;

    public string $importTemplate = '';

    public function createSheetFrom(string $templateUuid)
    {
        $template = Template::where('uuid', $templateUuid)->firstOrFail();
        $this->authorize('view', $template);

        $sheet = app(CreateSheet::class)($template, auth()->user());

        return $this->redirect(route('sheets.edit', $sheet), navigate: true);
    }

    /**
     * Al elegir el archivo: se crea una hoja nueva con sus valores. La
     * plantilla es la elegida o, si no se elige, aquella de la que salió.
     */
    public function updatedSheetFile()
    {
        $file = $this->sheetFile;
        $this->sheetFile = null;

        if (! $file instanceof TemporaryUploadedFile) {
            return null;
        }

        $transfer = app(SheetTransfer::class);

        try {
            if ($file->getSize() > 2 * 1024 * 1024) {
                throw new TransferException('El archivo pesa más de 2 MB.');
            }

            $json = json_decode($file->get(), true);
            $template = $this->importTemplate !== ''
                ? Template::where('uuid', $this->importTemplate)->first()
                : $transfer->originalTemplate(auth()->user(), $json);

            if (! $template || ! auth()->user()->can('view', $template)) {
                throw new TransferException('No encuentro la plantilla de esa hoja: elige en qué plantilla importarla.');
            }

            [$sheet, $warnings] = $transfer->import(auth()->user(), $json, $template);
        } catch (TransferException $e) {
            $this->addError('sheetFile', $e->getMessage());

            return null;
        } finally {
            $file->delete();
        }

        if ($warnings) {
            session()->flash('sheet_notice', implode(' ', $warnings));
        }

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
