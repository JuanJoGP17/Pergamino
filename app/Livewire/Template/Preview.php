<?php

namespace App\Livewire\Template;

use App\Domain\Schema\CompiledSchema;
use App\Domain\Schema\SchemaCompilationException;
use App\Domain\Schema\SchemaCompiler;
use App\Domain\Sheet\SheetCalculator;
use App\Domain\Sheet\SheetView;
use App\Models\Sheet;
use App\Models\Template;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Vista previa en vivo del borrador (§6.2, punto 1).
 *
 * Compila el borrador en memoria —sin publicar ni guardar nada— y lo pinta con
 * el MISMO cuerpo de hoja que el editor real (livewire.sheet._body), con sus
 * fórmulas, condiciones y recalculo al teclear. Lo que se escriba aquí son
 * datos de prueba: viven en el componente y se pierden al recargar.
 *
 * El constructor le avisa con el evento `template-changed` cada vez que toca
 * el borrador.
 */
class Preview extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $templateUuid;

    /** Datos de prueba. */
    public array $data = [];

    public string $tab = '';

    public function mount(string $templateUuid): void
    {
        $this->templateUuid = $templateUuid;
        $this->authorize('update', $this->template());
    }

    #[On('template-changed')]
    public function refresh(): void
    {
        // Basta con volver a pintar: render() recompila el borrador.
    }

    public function selectTab(string $key): void
    {
        $this->tab = $key;
    }

    public function resetData(): void
    {
        $this->data = [];
    }

    public function render()
    {
        try {
            $schema = (new SchemaCompiler)->compile($this->template());
        } catch (SchemaCompilationException $e) {
            return view('livewire.template.preview', ['error' => $e->getMessage()]);
        }

        // Los campos nuevos aparecen con su valor por defecto; los que ya no
        // existen se olvidan.
        $data = array_intersect_key($this->data, $schema->defaultData()) + $schema->defaultData();
        $this->data = $data;

        [$computed, $errors] = (new SheetCalculator)->calculateWithErrors($schema, $data);

        return view('livewire.template.preview', [
            'error' => null,
            'sheet' => $this->fakeSheet($schema, $data, $computed),
            ...(new SheetView($schema, $data, $computed))->viewData($this->tab, $errors),
        ]);
    }

    private function template(): Template
    {
        return Template::where('uuid', $this->templateUuid)->firstOrFail();
    }

    /** Hoja sin guardar: los parciales de campo leen de ella `computed`. */
    private function fakeSheet(CompiledSchema $schema, array $data, array $computed): Sheet
    {
        $sheet = new Sheet(['name' => 'Vista previa', 'data' => $data, 'computed' => $computed]);
        $sheet->uuid = 'preview-'.$this->templateUuid;

        return $sheet;
    }
}
