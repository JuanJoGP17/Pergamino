{{-- Iniciativa: el DJ la lleva; los demás la ven moverse (polling). --}}
<section wire:poll.4s class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-3" aria-label="Iniciativa">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="font-serif font-bold text-[var(--pg-accent)]">Iniciativa</h2>
        <span class="text-xs text-[var(--pg-muted)]" data-round>Ronda {{ $state['round'] }}</span>
    </div>

    @if ($error)
        <p class="mb-2 text-xs text-[var(--pg-accent)]" role="alert">{{ $error }}</p>
    @endif

    <ol class="space-y-1">
        @forelse ($state['entries'] as $i => $entry)
            <li wire:key="init-{{ $entry['id'] }}" data-turn="{{ $i === $state['turn'] ? 'current' : '' }}"
                class="flex items-center gap-2 rounded px-2 py-1 text-sm {{ $i === $state['turn'] ? 'bg-[var(--pg-accent)] text-white' : 'bg-[var(--pg-shade)]' }}">
                <span class="w-4 text-xs opacity-70">{{ $i === $state['turn'] ? '▶' : '' }}</span>
                <span class="min-w-0 flex-1 truncate">{{ $entry['name'] }}</span>
                @if ($isGm)
                    <input type="number" class="w-14 rounded border border-[var(--pg-border)] bg-[var(--pg-surface)] px-1 text-center text-[var(--pg-ink)]"
                           value="{{ $entry['value'] }}" aria-label="Iniciativa de {{ $entry['name'] }}"
                           wire:change="setValue('{{ $entry['id'] }}', $event.target.value)">
                    <button type="button" class="opacity-70 hover:opacity-100" wire:click="remove('{{ $entry['id'] }}')" aria-label="Quitar a {{ $entry['name'] }}">&times;</button>
                @else
                    <span class="font-semibold tabular-nums">{{ $entry['value'] }}</span>
                @endif
            </li>
        @empty
            <li class="text-xs text-[var(--pg-muted)]">Sin combate en curso.</li>
        @endforelse
    </ol>

    @if ($isGm)
        <div class="mt-3 space-y-2 border-t border-[var(--pg-border)] pt-2">
            <div class="flex gap-1">
                <button type="button" wire:click="previous" class="pg-btn-ghost flex-1 py-1 text-xs">← Anterior</button>
                <button type="button" wire:click="next" class="pg-btn flex-1 py-1 text-xs">Siguiente turno →</button>
            </div>
            <form wire:submit="add" class="flex gap-1">
                <input type="text" wire:model="name" class="pg-input py-1 text-xs" placeholder="Goblin, Orco…" aria-label="Nombre">
                <input type="number" wire:model="value" class="pg-input w-14 py-1 text-xs" placeholder="0" aria-label="Valor">
                <button type="submit" class="pg-btn-ghost py-1 text-xs">+</button>
            </form>
            <div class="flex justify-between text-xs">
                <button type="button" wire:click="addSheets" class="text-[var(--pg-muted)] hover:text-[var(--pg-accent)]"
                        title="Tira la iniciativa de cada hoja de la mesa que tenga ese campo">Tirar las de las hojas</button>
                <button type="button" wire:click="resetCombat" wire:confirm="¿Terminar el combate y vaciar la lista?" class="text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">Terminar combate</button>
            </div>
        </div>
    @endif
</section>
