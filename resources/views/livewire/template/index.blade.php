<div class="space-y-10">
    <section>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <h1 class="font-serif text-xl font-bold text-[var(--pg-accent)]">Mis plantillas</h1>

            <form wire:submit="create" class="flex items-start gap-2">
                <div>
                    <label for="new-template" class="sr-only">Nombre de la nueva plantilla</label>
                    <input id="new-template" type="text" wire:model="name" class="pg-input w-64"
                           placeholder="Nombre del sistema, p. ej. «Vampiro casero»">
                    @error('name') <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="pg-btn whitespace-nowrap">Nueva plantilla</button>
            </form>
        </div>

        <div class="space-y-2">
            @forelse ($mine as $template)
                <div wire:key="mine-{{ $template->id }}"
                     class="flex flex-wrap items-center gap-3 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4">
                    <div class="min-w-48 flex-1">
                        <a href="{{ route('templates.builder', $template) }}" wire:navigate
                           class="font-serif font-bold text-[var(--pg-accent)] hover:underline">{{ $template->name }}</a>
                        @if ($template->is_official)
                            <span class="ml-1 rounded bg-[var(--pg-shade)] px-1.5 py-0.5 text-[0.6rem] uppercase text-[var(--pg-muted)]">oficial</span>
                        @endif
                        <p class="text-xs text-[var(--pg-muted)]">
                            {{ $template->game_line }} ·
                            {{ $template->currentVersion ? 'v'.$template->currentVersion->version : 'sin publicar' }}
                            @if (\App\Domain\Builder\TemplateEditor::hasUnpublishedChanges($template) && $template->currentVersion)
                                · <span class="text-[var(--pg-accent)]">cambios sin publicar</span>
                            @endif
                            · {{ $template->sheets_count }} hoja(s)
                        </p>
                    </div>
                    <a href="{{ route('templates.builder', $template) }}" wire:navigate class="pg-btn-ghost text-sm">Abrir el constructor</a>
                </div>
            @empty
                <p class="rounded-lg border border-dashed border-[var(--pg-border)] p-6 text-center text-sm text-[var(--pg-muted)]">
                    Todavía no has creado ninguna plantilla. Ponle nombre arriba y empieza a construir tu sistema.
                </p>
            @endforelse
        </div>
    </section>

    @if ($public->isNotEmpty())
        <section>
            <h2 class="mb-4 font-serif text-lg font-bold text-[var(--pg-accent)]">Plantillas públicas</h2>
            <div class="space-y-2">
                @foreach ($public as $template)
                    <div wire:key="public-{{ $template->id }}"
                         class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4">
                        <span class="font-serif font-bold">{{ $template->name }}</span>
                        @if ($template->is_official)
                            <span class="ml-1 rounded bg-[var(--pg-shade)] px-1.5 py-0.5 text-[0.6rem] uppercase text-[var(--pg-muted)]">oficial</span>
                        @endif
                        <p class="text-xs text-[var(--pg-muted)]">
                            {{ $template->tagline ?: $template->game_line }} · {{ $template->sheets_count }} hoja(s)
                        </p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
