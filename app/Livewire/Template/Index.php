<?php

namespace App\Livewire\Template;

use App\Domain\Builder\TemplateEditor;
use App\Domain\Transfer\TemplateTransfer;
use App\Domain\Transfer\TransferException;
use App\Models\Template;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Mis plantillas y las públicas. El catálogo completo, con búsqueda y filtros,
 * llega en la Fase 7.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public string $name = '';

    /** JSON de una plantilla exportada (§6.2, punto 7). */
    public $importFile = null;

    public function create()
    {
        $this->authorize('create', Template::class);
        $this->validate(['name' => ['required', 'string', 'max:120']], [], ['name' => 'nombre']);

        $template = app(TemplateEditor::class)->create(auth()->user(), $this->name);

        return $this->redirect(route('templates.builder', $template), navigate: true);
    }

    /** Al elegir el archivo: se importa como borrador nuevo y se abre en el constructor. */
    public function updatedImportFile()
    {
        $this->authorize('create', Template::class);
        $file = $this->importFile;
        $this->importFile = null;

        if (! $file instanceof TemporaryUploadedFile) {
            return null;
        }

        try {
            if ($file->getSize() > 2 * 1024 * 1024) {
                throw new TransferException('El archivo pesa más de 2 MB.');
            }

            [$template, $warnings] = app(TemplateTransfer::class)->import(auth()->user(), json_decode($file->get(), true));
        } catch (TransferException $e) {
            $this->addError('importFile', $e->getMessage());

            return null;
        } finally {
            $file->delete();
        }

        session()->flash('notice', ['type' => $warnings ? 'error' : 'ok', 'text' => 'Plantilla importada como borrador.'
            .($warnings ? ' '.implode(' ', $warnings) : '')]);

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
