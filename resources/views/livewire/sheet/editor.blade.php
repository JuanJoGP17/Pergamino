{{--
    Editor de hoja: cabecera (nombre, plantilla, estado de guardado) y el
    cuerpo compartido con la vista previa del constructor (_body).
--}}
<div class="space-y-6">

    {{-- Cabecera --}}
    <div class="flex flex-wrap items-center gap-3">
        <input type="text"
               value="{{ $sheet->name }}"
               wire:change="rename($event.target.value)"
               class="flex-1 min-w-64 border-0 border-b border-transparent bg-transparent px-0 font-serif text-2xl font-bold text-[var(--pg-accent)] focus:border-[var(--pg-border)] focus:outline-none">

        <span class="text-xs text-[var(--pg-muted)]">
            {{ $schema->template['name'] ?? 'Plantilla' }}
            @if ($sheet->is_template_dirty)
                · <span class="text-[var(--pg-accent)]">hay una versión más nueva de la plantilla</span>
            @endif
        </span>

        @foreach ($rests as $kind => $label)
            <button type="button" wire:click="rest('{{ $kind }}')"
                    wire:confirm="¿{{ $label }}? Se recuperan los recursos y usos que la plantilla marca."
                    class="pg-btn-ghost py-1 text-xs">{{ $label }}</button>
        @endforeach

        <span class="text-xs text-[var(--pg-muted)]" wire:loading.remove wire:target="save">
            @if ($savedAt) Guardado {{ $savedAt }} @endif
        </span>
        <span class="text-xs text-[var(--pg-muted)]" wire:loading wire:target="save">Guardando…</span>
    </div>

    @include('livewire.sheet._body')
</div>
