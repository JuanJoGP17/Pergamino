{{--
    Vista de mesa (§8). Las tres piezas que se mueven solas mientras se juega
    —grupo, iniciativa y registro de tiradas— son componentes hijos con su
    propio polling.
--}}
<div class="space-y-4" x-data="{ panel: 'notas' }">
    {{-- Cabecera --}}
    <div class="flex flex-wrap items-start gap-4 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] px-4 py-3">
        <div class="min-w-64 flex-1">
            <a href="{{ route('campaigns.index') }}" wire:navigate class="text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">← Mesas</a>
            <h1 class="font-serif text-2xl font-bold text-[var(--pg-accent)]">{{ $campaign->name }}</h1>
            @if ($campaign->description)
                <p class="text-sm text-[var(--pg-muted)]">{{ $campaign->description }}</p>
            @endif
            <p class="mt-1 text-xs text-[var(--pg-muted)]">
                DJ: {{ $campaign->gm?->displayName() }} ·
                {{ ['gm' => 'diriges esta mesa', 'player' => 'juegas en esta mesa', 'spectator' => 'miras esta mesa'][$role] ?? '' }}
            </p>
        </div>

        @if ($isGm)
            <div class="text-right text-sm" x-data="{ copied: false }">
                <p class="text-xs text-[var(--pg-muted)]">Código para unirse</p>
                <p class="font-mono text-lg font-bold tracking-wide" data-join-code>{{ $campaign->join_code }}</p>
                <div class="flex justify-end gap-3 text-xs">
                    <button type="button" class="text-[var(--pg-muted)] hover:text-[var(--pg-accent)]"
                            x-on:click="navigator.clipboard?.writeText(@js(route('campaigns.join', $campaign->join_code))); copied = true; setTimeout(() => copied = false, 1500)">
                        <span x-show="! copied">Copiar enlace de invitación</span><span x-show="copied" x-cloak>¡Copiado!</span>
                    </button>
                    <button type="button" class="text-[var(--pg-muted)] hover:text-[var(--pg-accent)]" wire:click="regenerateCode"
                            wire:confirm="¿Cambiar el código? El anterior dejará de servir.">Cambiar código</button>
                </div>
            </div>
        @endif
    </div>

    @if ($notice)
        <p role="status" class="rounded-md border px-3 py-2 text-sm {{ $notice['type'] === 'error' ? 'border-[var(--pg-accent)] text-[var(--pg-accent)]' : 'border-[var(--pg-border)]' }}">{{ $notice['text'] }}</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-4">
            {{-- Grupo --}}
            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-serif text-lg font-bold text-[var(--pg-accent)]">El grupo</h2>

                    @if (in_array($role, ['gm', 'player'], true))
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            @if ($mySheets->isNotEmpty())
                                <form wire:submit="addSheet" class="flex items-center gap-1">
                                    <select class="pg-input w-48 py-1 text-xs" wire:model="sheetToAdd" aria-label="Hoja que llevar a la mesa">
                                        <option value="">Llevar una hoja…</option>
                                        @foreach ($mySheets as $s)
                                            <option value="{{ $s->uuid }}">{{ $s->name }}</option>
                                        @endforeach
                                    </select>
                                    <select class="pg-input w-32 py-1 text-xs" wire:model="shareLevel" aria-label="Cuánto se ve">
                                        <option value="summary">Solo resumen</option>
                                        <option value="full">Completa</option>
                                        <option value="hidden">Oculta</option>
                                    </select>
                                    <button type="submit" class="pg-btn-ghost py-1 text-xs">Añadir</button>
                                </form>
                            @endif
                            @if ($campaign->defaultTemplate)
                                <button type="button" wire:click="createSheet" class="pg-btn py-1 text-xs">
                                    Crear mi hoja de {{ $campaign->defaultTemplate->name }}
                                </button>
                            @endif
                        </div>
                    @endif
                </div>

                <livewire:campaign.party :campaign-uuid="$campaign->uuid" :key="'party-'.$campaign->uuid" />
            </section>

            {{-- Notas, personas y ajustes --}}
            <section class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4">
                <div class="mb-3 flex gap-1 border-b border-[var(--pg-border)] text-sm" role="tablist">
                    <button type="button" role="tab" class="-mb-px border-b-2 px-3 py-1.5" x-on:click="panel = 'notas'"
                            x-bind:class="panel === 'notas' ? 'border-[var(--pg-accent)] font-semibold text-[var(--pg-accent)]' : 'border-transparent text-[var(--pg-muted)]'">Notas</button>
                    <button type="button" role="tab" class="-mb-px border-b-2 px-3 py-1.5" x-on:click="panel = 'personas'"
                            x-bind:class="panel === 'personas' ? 'border-[var(--pg-accent)] font-semibold text-[var(--pg-accent)]' : 'border-transparent text-[var(--pg-muted)]'">Personas ({{ $members->count() }})</button>
                    @if ($isGm)
                        <button type="button" role="tab" class="-mb-px border-b-2 px-3 py-1.5" x-on:click="panel = 'ajustes'"
                                x-bind:class="panel === 'ajustes' ? 'border-[var(--pg-accent)] font-semibold text-[var(--pg-accent)]' : 'border-transparent text-[var(--pg-muted)]'">Ajustes</button>
                    @endif
                </div>

                {{-- Notas --}}
                <div x-show="panel === 'notas'" class="space-y-3">
                    @if (in_array($role, ['gm', 'player'], true))
                        <form wire:submit="saveNote" class="space-y-2 rounded-md border border-dashed border-[var(--pg-border)] p-3">
                            <input type="text" wire:model="noteTitle" class="pg-input" placeholder="Título" aria-label="Título de la nota">
                            <textarea rows="3" wire:model="noteBody" class="pg-input" placeholder="Texto (admite **markdown**)" aria-label="Texto de la nota"></textarea>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                @if ($isGm)
                                    <label class="flex items-center gap-1"><input type="checkbox" wire:model="noteGmOnly" class="accent-[var(--pg-accent)]"> Solo para el DJ</label>
                                @endif
                                <button type="submit" class="pg-btn py-1 text-xs">{{ $noteId ? 'Guardar cambios' : 'Añadir nota' }}</button>
                                @if ($noteId)
                                    <button type="button" wire:click="cancelNote" class="text-xs text-[var(--pg-muted)]">Cancelar</button>
                                @endif
                            </div>
                        </form>
                    @endif

                    @forelse ($notes as $note)
                        <article wire:key="note-{{ $note->id }}" class="rounded-md border border-[var(--pg-border)] p-3 {{ $note->is_gm_only ? 'bg-[var(--pg-shade)]' : '' }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="font-serif font-bold">
                                    @if ($note->is_gm_only)<span title="Solo la ve el DJ">🔒</span>@endif
                                    {{ $note->title ?: 'Sin título' }}
                                </h3>
                                <span class="text-xs text-[var(--pg-muted)]">
                                    {{ $note->author?->displayName() }} · {{ $note->updated_at->diffForHumans() }}
                                    @if ($isGm || $note->author_id === auth()->id())
                                        · <button type="button" wire:click="editNote({{ $note->id }})" class="hover:text-[var(--pg-accent)]">Editar</button>
                                        · <button type="button" wire:click="deleteNote({{ $note->id }})" wire:confirm="¿Borrar la nota?" class="hover:text-[var(--pg-accent)]">Borrar</button>
                                    @endif
                                </span>
                            </div>
                            {{-- Markdown en modo seguro (§10): sin HTML crudo ni enlaces peligrosos. --}}
                            <div class="pg-markdown mt-1 text-sm">{!! \Illuminate\Support\Str::markdown((string) $note->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                        </article>
                    @empty
                        <p class="text-sm text-[var(--pg-muted)]">Sin notas todavía.</p>
                    @endforelse
                </div>

                {{-- Personas --}}
                <div x-show="panel === 'personas'" x-cloak class="space-y-1">
                    @foreach ($members as $member)
                        <div wire:key="member-{{ $member->id }}" class="flex flex-wrap items-center gap-2 border-b border-[var(--pg-border)] py-1.5 text-sm last:border-0">
                            <span class="min-w-32 flex-1">{{ $member->user?->displayName() }}</span>
                            @if ($isGm && $member->role !== 'gm')
                                <select class="pg-input w-36 py-1 text-xs" wire:change="setRole({{ $member->user_id }}, $event.target.value)" aria-label="Rol">
                                    <option value="player" @selected($member->role === 'player')>Jugador</option>
                                    <option value="spectator" @selected($member->role === 'spectator')>Espectador</option>
                                </select>
                                <button type="button" wire:click="removeMember({{ $member->user_id }})" wire:confirm="¿Echar a {{ $member->user?->displayName() }} de la mesa?"
                                        class="text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">Echar</button>
                            @else
                                <span class="text-xs text-[var(--pg-muted)]">{{ ['gm' => 'DJ', 'player' => 'Jugador', 'spectator' => 'Espectador'][$member->role] ?? $member->role }}</span>
                            @endif
                        </div>
                    @endforeach
                    @unless ($isGm)
                        <button type="button" wire:click="removeMember({{ auth()->id() }})" wire:confirm="¿Irte de la mesa? Tus hojas saldrán de ella."
                                class="mt-2 text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">Irme de la mesa</button>
                    @endunless
                </div>

                {{-- Ajustes del DJ --}}
                @if ($isGm)
                    <div x-show="panel === 'ajustes'" x-cloak class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="pg-label" for="s-name">Nombre</label>
                            <input id="s-name" type="text" class="pg-input" wire:model.live.blur="settings.name">
                        </div>
                        <div>
                            <label class="pg-label" for="s-template">Plantilla sugerida</label>
                            <select id="s-template" class="pg-input" wire:model.live="settings.default_template_id">
                                <option value="">Ninguna</option>
                                @foreach ($templates as $t)
                                    <option value="{{ $t->id }}">{{ $t->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="pg-label" for="s-desc">Descripción</label>
                            <textarea id="s-desc" rows="2" class="pg-input" wire:model.live.blur="settings.description"></textarea>
                        </div>
                        <div>
                            <label class="pg-label" for="s-theme">Tema de la mesa (§6.3)</label>
                            <select id="s-theme" class="pg-input" wire:model.live="settings.theme_preset">
                                <option value="">El de cada plantilla</option>
                                @foreach ($presets as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <label class="flex items-center gap-2 self-end text-sm">
                            <input type="checkbox" wire:model.live="settings.gm_can_edit_sheets" class="accent-[var(--pg-accent)]">
                            El DJ puede editar las hojas de la mesa
                        </label>
                    </div>
                @endif
            </section>
        </div>

        {{-- Columna de juego --}}
        <aside class="space-y-4 self-start lg:sticky lg:top-4">
            <livewire:campaign.initiative-tracker :campaign-uuid="$campaign->uuid" :key="'initiative-'.$campaign->uuid" />
            <livewire:campaign.roll-log :campaign-uuid="$campaign->uuid" :key="'log-'.$campaign->uuid" />
        </aside>
    </div>
</div>
