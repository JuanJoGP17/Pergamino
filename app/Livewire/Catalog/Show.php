<?php

namespace App\Livewire\Catalog;

use App\Domain\Catalog\Catalog;
use App\Domain\Catalog\CatalogException;
use App\Domain\Sheet\CreateSheet;
use App\Domain\Sheet\SheetCalculator;
use App\Domain\Sheet\SheetPrinter;
use App\Domain\Theme\SheetTheme;
use App\Models\Sheet;
use App\Models\Template;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Ficha de una plantilla en el catálogo (Fase 7): qué es, cómo se ve (vista
 * previa de una hoja nueva) y qué se puede hacer con ella: usarla, clonarla,
 * darle a me gusta, exportarla o reportarla.
 */
#[Layout('components.layouts.app')]
class Show extends Component
{
    #[Locked]
    public string $slug;

    public string $reportReason = '';

    public string $reportDetails = '';

    public ?array $notice = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $this->template();   // 404 si no existe o no se puede ver
    }

    public function useTemplate()
    {
        $sheet = app(CreateSheet::class)($this->template(), auth()->user());

        return $this->redirect(route('sheets.edit', $sheet), navigate: true);
    }

    public function fork()
    {
        try {
            [$copy] = app(Catalog::class)->fork(auth()->user(), $this->template());
        } catch (CatalogException $e) {
            $this->notice = ['type' => 'error', 'text' => $e->getMessage()];

            return null;
        }

        session()->flash('notice', ['type' => 'ok', 'text' => 'Plantilla clonada: es tuya y privada. Cámbiala y publícala cuando quieras.']);

        return $this->redirect(route('templates.builder', $copy), navigate: true);
    }

    public function toggleLike(): void
    {
        app(Catalog::class)->toggleLike(auth()->user(), $this->template());
    }

    public function report(): void
    {
        try {
            app(Catalog::class)->report(auth()->user(), $this->template(), $this->reportReason, $this->reportDetails);
            $this->notice = ['type' => 'ok', 'text' => 'Gracias: el reporte queda registrado para revisarlo.'];
            $this->reset('reportReason', 'reportDetails');
        } catch (CatalogException $e) {
            $this->notice = ['type' => 'error', 'text' => $e->getMessage()];
        }
    }

    public function render()
    {
        $template = $this->template()->load(['owner:id,name', 'currentVersion', 'forkedFrom:id,name,slug,visibility']);
        $user = auth()->user();

        return view('livewire.catalog.show', [
            'template' => $template,
            'version' => $template->currentVersion,
            'liked' => app(Catalog::class)->likes($user, $template),
            'isOwner' => $user->id === $template->owner_id,
            'preview' => $this->preview($template),
            'theme' => SheetTheme::forTemplate($template),
            'forks' => Template::public()->whereNotNull('current_version_id')->where('forked_from_id', $template->id)->count(),
            'reasons' => Catalog::REPORT_REASONS,
        ]);
    }

    private function template(): Template
    {
        $template = Template::where('slug', $this->slug)->whereNotNull('current_version_id')->first();

        abort_unless($template && auth()->user()?->can('view', $template), 404);

        return $template;
    }

    /**
     * Cómo es una hoja nueva: todas las pestañas con sus valores por defecto,
     * con el mismo modelo que la vista de impresión. No se guarda nada.
     */
    private function preview(Template $template): array
    {
        $schema = $template->currentVersion->schema();
        $data = $schema->defaultData();

        $sheet = new Sheet(['name' => $template->name, 'data' => $data, 'computed' => (new SheetCalculator)->calculate($schema, $data)]);
        $sheet->setRelation('version', $template->currentVersion);

        return (new SheetPrinter($sheet))->tabs();
    }
}
