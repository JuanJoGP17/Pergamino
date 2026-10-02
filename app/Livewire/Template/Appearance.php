<?php

namespace App\Livewire\Template;

use App\Domain\Media\ImageRejected;
use App\Domain\Media\StoreImage;
use App\Domain\Theme\Fonts;
use App\Domain\Theme\Presets;
use App\Domain\Theme\SaveTemplateTheme;
use App\Domain\Theme\SheetTheme;
use App\Domain\Theme\Theme;
use App\Models\Template;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Editor de apariencia de una plantilla (§6.3).
 *
 *   CONTROLES | VISTA PREVIA (la hoja real, con el tema aplicado)
 *
 * El formulario es el tema EFECTIVO completo; cada cambio se guarda entero en
 * la plantilla (SaveTemplateTheme lo sanea) y la vista previa se repinta.
 * Elegir un preset reemplaza todo por el del preset: es «empezar desde».
 *
 * Se aplica al momento a todas las hojas de la plantilla, sin publicar: el
 * tema es apariencia, no estructura (ver SheetTheme).
 */
#[Layout('components.layouts.app', ['wide' => true])]
class Appearance extends Component
{
    use AuthorizesRequests, WithFileUploads;

    #[Locked]
    public string $templateUuid;

    public array $form = [];

    /** Imagen de fondo recién elegida. */
    public $background = null;

    public ?string $saved = null;

    public function mount(Template $template): void
    {
        $this->authorize('update', $template);
        $this->templateUuid = $template->uuid;
        $this->form = SheetTheme::forTemplate($template);
    }

    public function choosePreset(string $preset): void
    {
        if (! isset(Presets::all()[$preset])) {
            return;
        }

        $background = $this->form['custom_background'] ?? [];
        $this->form = Theme::resolve(['preset' => $preset]);
        $this->form['custom_background'] = $background + $this->form['custom_background'];
        $this->persist();
    }

    public function updatedForm(): void
    {
        $this->persist();
    }

    public function updatedBackground(): void
    {
        $template = $this->template();
        $this->authorize('update', $template);
        $file = $this->background;
        $this->background = null;

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $media = app(StoreImage::class)($file, auth()->user(), 'background');
        } catch (ImageRejected $e) {
            $this->addError('background', $e->getMessage());

            return;
        } finally {
            $file->delete();
        }

        $this->form['custom_background']['media_id'] = $media->id;
        $this->persist();
    }

    public function removeBackground(): void
    {
        $this->form['custom_background']['media_id'] = null;
        $this->persist();
    }

    /** Vuelve al preset por defecto, sin ningún cambio. */
    public function resetTheme(): void
    {
        $template = $this->template();
        $this->authorize('update', $template);
        $template->forceFill(['theme' => null])->save();
        $this->form = SheetTheme::forTemplate($template);
        $this->afterSave();
    }

    public function render()
    {
        return view('livewire.template.appearance', [
            'template' => $this->template(),
            'presets' => Presets::all(),
            'fonts' => Fonts::options(),
        ]);
    }

    private function persist(): void
    {
        $template = $this->template();
        $this->authorize('update', $template);

        // Lo guardado es lo que manda: si algo no pasó la lista blanca, el
        // formulario vuelve a lo válido.
        $saved = app(SaveTemplateTheme::class)($template, $this->form);
        $this->form = Theme::resolve($saved);
        $this->afterSave();
    }

    private function afterSave(): void
    {
        $this->saved = now()->format('H:i:s');
        $this->dispatch('theme-changed')->to(Preview::class);
    }

    private function template(): Template
    {
        return Template::where('uuid', $this->templateUuid)->firstOrFail();
    }
}
