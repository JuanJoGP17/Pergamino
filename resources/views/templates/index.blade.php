<x-layouts.app>
    <h1 class="mb-4 font-serif text-xl font-bold text-[var(--pg-accent)]">Plantillas</h1>

    <div class="space-y-2">
        @forelse ($templates as $template)
            <div class="flex items-center gap-3 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4">
                <div class="flex-1">
                    <span class="font-serif font-bold">{{ $template->name }}</span>
                    @if ($template->is_official)
                        <span class="ml-1 rounded bg-[var(--pg-shade)] px-1.5 py-0.5 text-[0.6rem] uppercase text-[var(--pg-muted)]">oficial</span>
                    @endif
                    <p class="text-xs text-[var(--pg-muted)]">
                        {{ $template->game_line }} ·
                        {{ $template->isPublished() ? 'v'.$template->currentVersion->version : 'sin publicar' }} ·
                        {{ $template->sheets_count }} hoja(s)
                    </p>
                </div>
            </div>
        @empty
            <p class="text-sm text-[var(--pg-muted)]">Todavía no hay plantillas.</p>
        @endforelse
    </div>

    <p class="mt-6 text-sm text-[var(--pg-muted)]">
        El constructor visual llega en la Fase 3. De momento, las plantillas se crean por seeder.
    </p>
</x-layouts.app>
