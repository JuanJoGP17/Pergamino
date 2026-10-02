{{--
    Vista previa del borrador: la hoja real (livewire.sheet._body) con datos de
    prueba que no se guardan.
--}}
<div class="space-y-3">
    @if ($error)
        <p class="rounded-lg border border-dashed border-[var(--pg-accent)] p-6 text-center text-sm text-[var(--pg-accent)]">
            Todavía no se puede previsualizar: {{ $error }}
        </p>
    @else
        <div class="flex items-center justify-between text-xs text-[var(--pg-muted)]">
            <span>Así se verá una hoja nueva. Lo que escribas aquí no se guarda.</span>
            <button type="button" wire:click="resetData" class="hover:text-[var(--pg-accent)]">Vaciar datos de prueba</button>
        </div>

        @include('livewire.sheet._body')
    @endif
</div>
