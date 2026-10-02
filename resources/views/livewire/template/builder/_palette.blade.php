{{--
    Paleta: arrastra un tipo a una sección del lienzo, o haz clic para añadirlo
    a la sección seleccionada. Los tipos de la Fase 4 aún no aparecen.
--}}
<aside class="space-y-4 self-start rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-3 lg:sticky lg:top-4">
    <p class="text-xs text-[var(--pg-muted)]">Arrastra al lienzo o haz clic.</p>

    @foreach ($palette as $group => $groupTypes)
        <div>
            <h2 class="mb-1 text-[0.65rem] font-semibold uppercase tracking-wide text-[var(--pg-muted)]">{{ $group }}</h2>
            <div class="space-y-1" x-sortable="{ group: 'fields', clone: true }">
                @foreach ($groupTypes as $type)
                    <div data-palette-type="{{ $type->value }}"
                         wire:key="palette-{{ $type->value }}"
                         wire:click="addField('{{ $type->value }}')"
                         class="cursor-grab select-none rounded border border-[var(--pg-border)] bg-[var(--pg-bg)] px-2 py-1.5 text-sm hover:border-[var(--pg-accent)]">
                        {{ $type->label() }}
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</aside>
