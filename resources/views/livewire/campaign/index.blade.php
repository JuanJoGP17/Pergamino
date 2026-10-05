<div class="space-y-8">
    <section>
        <h1 class="mb-4 font-serif text-xl font-bold text-[var(--pg-accent)]">Mis mesas</h1>

        <div class="space-y-2">
            @forelse ($campaigns as $campaign)
                <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate wire:key="campaign-{{ $campaign->id }}"
                   class="flex flex-wrap items-center gap-3 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4 hover:border-[var(--pg-accent)]">
                    <span class="min-w-48 flex-1">
                        <span class="font-serif font-bold text-[var(--pg-accent)]">{{ $campaign->name }}</span>
                        <span class="block text-xs text-[var(--pg-muted)]">
                            DJ: {{ $campaign->gm?->displayName() }} · {{ $campaign->members_count }} persona(s) · {{ $campaign->sheets_count }} hoja(s)
                        </span>
                    </span>
                    <span class="rounded bg-[var(--pg-shade)] px-2 py-0.5 text-xs">
                        {{ ['gm' => 'Diriges', 'player' => 'Juegas', 'spectator' => 'Miras'][$campaign->pivot->role] ?? $campaign->pivot->role }}
                    </span>
                </a>
            @empty
                <p class="rounded-lg border border-dashed border-[var(--pg-border)] p-6 text-center text-sm text-[var(--pg-muted)]">
                    Todavía no estás en ninguna mesa. Crea una o únete con el código que te pase tu DJ.
                </p>
            @endforelse
        </div>
    </section>

    <div class="grid gap-6 md:grid-cols-2">
        <section class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4">
            <h2 class="mb-3 font-serif text-lg font-bold text-[var(--pg-accent)]">Crear una mesa</h2>
            <form wire:submit="create" class="space-y-3">
                <div>
                    <label class="pg-label" for="c-name">Nombre</label>
                    <input id="c-name" type="text" wire:model="name" class="pg-input" placeholder="La cripta del rey caído">
                    @error('name') <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="pg-label" for="c-desc">Descripción</label>
                    <textarea id="c-desc" rows="2" wire:model="description" class="pg-input"></textarea>
                </div>
                <div>
                    <label class="pg-label" for="c-template">Plantilla sugerida</label>
                    <select id="c-template" wire:model="templateId" class="pg-input">
                        <option value="">Ninguna</option>
                        @foreach ($templates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[0.7rem] text-[var(--pg-muted)]">Quien se una podrá crear su hoja con ella de un clic.</p>
                </div>
                <button type="submit" class="pg-btn">Crear la mesa</button>
            </form>
        </section>

        <section class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4">
            <h2 class="mb-3 font-serif text-lg font-bold text-[var(--pg-accent)]">Unirse a una mesa</h2>
            <form wire:submit="join" class="space-y-3">
                <div>
                    <label class="pg-label" for="c-code">Código de la mesa</label>
                    <input id="c-code" type="text" wire:model="code" class="pg-input font-mono uppercase" placeholder="AURORA-7421" autocomplete="off">
                    @error('code') <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="pg-btn">Unirme</button>
            </form>
        </section>
    </div>
</div>
