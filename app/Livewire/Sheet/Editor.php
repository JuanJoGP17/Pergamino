<?php

namespace App\Livewire\Sheet;

use App\Domain\Media\ImageRejected;
use App\Domain\Media\StoreImage;
use App\Domain\Schema\CompiledSchema;
use App\Domain\Schema\FieldType;
use App\Domain\Sheet\SaveSheet;
use App\Domain\Sheet\SheetView;
use App\Domain\Sheet\TakeRest;
use App\Models\Sheet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Editor de una hoja de personaje.
 *
 * El esquema manda: este componente no sabe nada de D&D ni de ningún sistema
 * concreto. Recorre el compiled_schema de la versión a la que está anclada la
 * hoja y pinta lo que encuentre.
 *
 * Guardado: `data` se enlaza con `wire:model.live.blur`, así que cada campo guarda al
 * perder el foco en vez de en cada pulsación. Para la Fase 3 esto pasará a un
 * autoguardado por diferencias con debounce (§11).
 */
#[Layout('components.layouts.app')]
class Editor extends Component
{
    use AuthorizesRequests, WithFileUploads;

    #[Locked]
    public string $sheetUuid;

    /** Valores editables, enlazados a la vista. */
    public array $data = [];

    public string $tab = '';

    public ?string $savedAt = null;

    /** Imágenes recién elegidas, por clave de campo (retratos, imágenes). */
    public array $uploads = [];

    private ?Sheet $sheetCache = null;

    public function mount(Sheet $sheet): void
    {
        $this->authorize('view', $sheet);

        $this->sheetUuid = $sheet->uuid;
        $this->data = $sheet->data ?? [];
        $this->tab = $sheet->schema()->firstTabKey() ?? '';
    }

    public function sheet(): Sheet
    {
        return $this->sheetCache ??= Sheet::with(['version', 'template'])
            ->where('uuid', $this->sheetUuid)
            ->firstOrFail();
    }

    public function schema(): CompiledSchema
    {
        return $this->sheet()->schema();
    }

    public function selectTab(string $key): void
    {
        if ($this->schema()->tab($key)) {
            $this->tab = $key;
        }
    }

    /** Se dispara al perder el foco cualquier campo. */
    public function save(): void
    {
        $sheet = $this->sheet();
        $this->authorize('update', $sheet);

        app(SaveSheet::class)($sheet, $this->data, auth()->user());

        // Releer para que los derivados recién calculados lleguen a la vista,
        // y adoptar los datos tal como quedaron guardados: SaveSheet puede
        // haber descartado claves o revertido un campo de solo lectura.
        $this->sheetCache = $sheet->refresh();
        $this->data = $sheet->data ?? [];
        $this->savedAt = now()->format('H:i:s');
    }

    public function updatedData(): void
    {
        $this->save();
    }

    /**
     * Al elegir un archivo en un campo de imagen: se valida, se reescribe y
     * se guarda (StoreImage), y el campo pasa a apuntar al Media nuevo.
     */
    public function updatedUploads(mixed $file, string $key): void
    {
        $sheet = $this->sheet();
        $this->authorize('update', $sheet);
        unset($this->uploads[$key]);

        $type = $this->schema()->type($key);

        if (! in_array($type, [FieldType::Image, FieldType::Portrait], true) || ! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $media = app(StoreImage::class)($file, auth()->user(), $type === FieldType::Portrait ? 'portrait' : 'image');
        } catch (ImageRejected $e) {
            $this->addError('uploads.'.$key, $e->getMessage());

            return;
        } finally {
            $file->delete();
        }

        $this->data[$key] = $media->id;
        $this->save();
    }

    public function clearImage(string $key): void
    {
        if (in_array($this->schema()->type($key), [FieldType::Image, FieldType::Portrait], true)) {
            $this->data[$key] = null;
            $this->save();
        }
    }

    /** Descanso corto o largo: recupera lo que la plantilla marque con reset_on. */
    public function rest(string $kind): void
    {
        $sheet = $this->sheet();
        $this->authorize('update', $sheet);

        if (! array_key_exists($kind, TakeRest::available($sheet))) {
            return;
        }

        app(TakeRest::class)($sheet, $kind, auth()->user());

        $this->sheetCache = $sheet->refresh();
        $this->data = $sheet->data ?? [];
        $this->savedAt = now()->format('H:i:s');
    }

    public function rename(string $name): void
    {
        $sheet = $this->sheet();
        $this->authorize('update', $sheet);

        $name = trim($name);
        $sheet->forceFill(['name' => $name !== '' ? mb_substr($name, 0, 160) : 'Personaje sin nombre'])->save();
    }

    /** Lo que necesita la vista: condiciones, tiradas y esquema para el navegador. */
    public function sheetView(): SheetView
    {
        $sheet = $this->sheet();

        return new SheetView($sheet->schema(), $this->data, $sheet->computed ?? []);
    }

    /** Atajo usado por los tests: el esquema mínimo que recibe el navegador. */
    public function clientSchema(): array
    {
        return $this->sheetView()->clientSchema();
    }

    public function render()
    {
        $sheet = $this->sheet();

        return view('livewire.sheet.editor', [
            'sheet' => $sheet,
            'rests' => TakeRest::available($sheet),
            'uploadsEnabled' => true,
            ...$this->sheetView()->viewData($this->tab, $sheet->formulaErrors()),
        ]);
    }
}
