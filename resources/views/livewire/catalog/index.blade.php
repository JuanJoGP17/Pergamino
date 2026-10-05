<div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-serif text-xl font-bold text-[var(--pg-accent)]">Catálogo de plantillas</h1>
            <p class="text-sm text-[var(--pg-muted)]">Sistemas de juego hechos por la comunidad. Úsalos tal cual o clónalos para adaptarlos.</p>
        </div>
        <a href="{{ route('templates.index') }}" wire:navigate class="pg-btn-ghost text-sm">Publicar la mía</a>
    </div>

    <div class="flex flex-wrap gap-2">
        <input type="search" wire:model.live.debounce.300ms="query" class="pg-input min-w-64 flex-1" placeholder="Buscar: vampiro, d20, terror…" aria-label="Buscar">
        <select wire:model.live="line" class="pg-input w-48" aria-label="Línea de juego">
            <option value="">Todas las líneas</option>
            @foreach ($lines as $name => $count)
                <option value="{{ $name }}">{{ $name }} ({{ $count }})</option>
            @endforeach
        </select>
        <select wire:model.live="sort" class="pg-input w-44" aria-label="Orden">
            @foreach ($sorts as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @if ($templates->isEmpty())
        <p class="rounded-lg border border-dashed border-[var(--pg-border)] p-8 text-center text-sm text-[var(--pg-muted)]">
            No hay plantillas que coincidan.
        </p>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-catalog>
            @foreach ($templates as $template)
                @php $preset = \App\Domain\Theme\SheetTheme::forTemplate($template); @endphp
                <a href="{{ route('catalog.show', $template->slug) }}" wire:navigate wire:key="tpl-{{ $template->id }}"
                   class="flex flex-col overflow-hidden rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] hover:border-[var(--pg-accent)]">
                    {{-- Franja con los colores del tema: se ve de un vistazo cómo será la hoja. --}}
                    <span class="flex h-2" aria-hidden="true">
                        @foreach (['bg', 'surface', 'accent', 'ink'] as $c)
                            <span class="flex-1" style="background: {{ $preset[$preset['mode'] === 'dark' ? 'colors_dark' : 'colors'][$c] }}"></span>
                        @endforeach
                    </span>
                    <span class="flex flex-1 flex-col p-4">
                        <span class="flex items-start justify-between gap-2">
                            <span class="font-serif font-bold text-[var(--pg-accent)]">{{ $template->name }}</span>
                            @if ($template->is_official)
                                <span class="shrink-0 rounded bg-[var(--pg-shade)] px-1.5 py-0.5 text-[0.6rem] uppercase text-[var(--pg-muted)]">oficial</span>
                            @endif
                        </span>
                        <span class="mt-1 flex-1 text-sm text-[var(--pg-muted)]">{{ $template->tagline ?: 'Sin descripción.' }}</span>
                        <span class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[var(--pg-muted)]">
                            <span class="rounded bg-[var(--pg-shade)] px-1.5 py-0.5">{{ $template->game_line ?: 'Casero' }}</span>
                            <span title="Personas que la usan">{{ $template->installs_count }} {{ $template->installs_count === 1 ? 'uso' : 'usos' }}</span>
                            <span title="Me gusta">♥ {{ $template->likes_count }}</span>
                            <span>de {{ $template->owner?->displayName() }}</span>
                            @if ($template->forkedFrom)
                                <span>· basada en {{ $template->forkedFrom->name }}</span>
                            @endif
                        </span>
                    </span>
                </a>
            @endforeach
        </div>

        {{ $templates->links() }}
    @endif
</div>
