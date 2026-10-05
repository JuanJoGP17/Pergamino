<div class="space-y-10">
    <section>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <h1 class="font-serif text-xl font-bold text-[var(--pg-accent)]">Mis hojas</h1>

            {{-- Importar una hoja exportada en JSON --}}
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <select class="pg-input w-56 py-1 text-xs" wire:model="importTemplate" aria-label="Plantilla para importar">
                    <option value="">La plantilla de la que salió</option>
                    @foreach ($templates as $template)
                        <option value="{{ $template->uuid }}">{{ $template->name }}</option>
                    @endforeach
                </select>
                <label class="pg-btn-ghost cursor-pointer py-1 text-xs">
                    Importar hoja (JSON)
                    <input type="file" accept="application/json,.json" class="sr-only" wire:model="sheetFile">
                </label>
                <span wire:loading wire:target="sheetFile" class="text-xs text-[var(--pg-muted)]">Importando…</span>
            </div>
        </div>
        @error('sheetFile') <p class="mb-3 text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror

        @if ($sheets->isEmpty())
            <p class="rounded-lg border border-dashed border-[var(--pg-border)] p-6 text-center text-sm text-[var(--pg-muted)]">
                Todavía no tienes hojas. Elige una plantilla abajo para crear la primera.
            </p>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($sheets as $sheet)
                    <div class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4">
                        <a href="{{ route('sheets.edit', $sheet) }}" wire:navigate
                           class="font-serif font-bold text-[var(--pg-accent)] hover:underline">
                            {{ $sheet->name }}
                        </a>
                        <p class="mt-1 text-xs text-[var(--pg-muted)]">
                            {{ $sheet->template?->name }} · {{ $sheet->updated_at->diffForHumans() }}
                        </p>
                        <button type="button"
                                wire:click="deleteSheet('{{ $sheet->uuid }}')"
                                wire:confirm="¿Seguro que quieres borrar «{{ $sheet->name }}»?"
                                class="mt-3 text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">
                            Borrar
                        </button>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section>
        <div class="mb-4 flex items-baseline justify-between">
            <h2 class="font-serif text-lg font-bold text-[var(--pg-accent)]">Mis mesas</h2>
            <a href="{{ route('campaigns.index') }}" wire:navigate class="text-sm text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">Crear o unirse →</a>
        </div>
        @if ($campaigns->isEmpty())
            <p class="text-sm text-[var(--pg-muted)]">Todavía no estás en ninguna mesa.</p>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($campaigns as $campaign)
                    <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate wire:key="dash-campaign-{{ $campaign->id }}"
                       class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4 hover:border-[var(--pg-accent)]">
                        <span class="font-serif font-bold text-[var(--pg-accent)]">{{ $campaign->name }}</span>
                        <span class="block text-xs text-[var(--pg-muted)]">
                            {{ ['gm' => 'Diriges', 'player' => 'Juegas', 'spectator' => 'Miras'][$campaign->pivot->role] ?? '' }} · {{ $campaign->sheets_count }} hoja(s)
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section>
        <h2 class="mb-4 font-serif text-lg font-bold text-[var(--pg-accent)]">Crear una hoja</h2>

        @if ($templates->isEmpty())
            <p class="text-sm text-[var(--pg-muted)]">
                No hay plantillas publicadas. Ejecuta <code class="font-mono">php artisan db:seed</code>
                para cargar la de ejemplo.
            </p>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($templates as $template)
                    <button type="button"
                            wire:click="createSheetFrom('{{ $template->uuid }}')"
                            class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4 text-left hover:border-[var(--pg-accent)]">
                        <span class="font-serif font-bold">{{ $template->name }}</span>
                        @if ($template->is_official)
                            <span class="ml-1 rounded bg-[var(--pg-shade)] px-1.5 py-0.5 text-[0.6rem] uppercase text-[var(--pg-muted)]">oficial</span>
                        @endif
                        <p class="mt-1 text-xs text-[var(--pg-muted)]">
                            {{ $template->tagline ?: $template->game_line }}
                        </p>
                    </button>
                @endforeach
            </div>
        @endif
    </section>
</div>
