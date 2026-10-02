{{--
    Constructor visual de plantillas (§6.1).

      PALETA | LIENZO o VISTA PREVIA | INSPECTOR
      ─────────── panel de validación ─────────

    Cada parcial de builder/ es una zona. El estado (qué está seleccionado, en
    qué pestaña) vive en App\Livewire\Template\Builder; Alpine solo guarda lo
    puramente visual: qué se ve en el centro y si el formulario de publicar
    está abierto.
--}}
<div class="space-y-4" x-data="{ view: 'canvas', device: 'desktop', publishing: false }">

    @include('livewire.template.builder._toolbar')

    @if ($notice)
        <div class="rounded-md border px-3 py-2 text-sm
                    {{ $notice['type'] === 'error'
                        ? 'border-[var(--pg-accent)] bg-[var(--pg-shade)] text-[var(--pg-accent)]'
                        : 'border-[var(--pg-border)] bg-[var(--pg-surface)]' }}"
             role="status">
            {{ $notice['text'] }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[12rem_minmax(0,1fr)_20rem]">
        @include('livewire.template.builder._palette')

        <div class="min-w-0">
            <div x-show="view === 'canvas'">
                @include('livewire.template.builder._canvas')
            </div>

            <div x-show="view === 'preview'" x-cloak style="display: none">
                <div class="mx-auto transition-all" x-bind:class="device === 'mobile' ? 'max-w-sm' : 'max-w-none'">
                    <livewire:template.preview :template-uuid="$templateUuid" :key="'preview-'.$templateUuid" />
                </div>
            </div>
        </div>

        @include('livewire.template.builder._inspector')
    </div>

    @include('livewire.template.builder._issues')
</div>
