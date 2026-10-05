{{-- Registro de tiradas de la mesa, compartido; se repinta solo cada 4 s. --}}
<section wire:poll.4s class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-3" aria-label="Registro de tiradas">
    <div class="mb-2 flex items-center justify-between gap-2">
        <h2 class="font-serif font-bold text-[var(--pg-accent)]">Tiradas</h2>
        <select class="pg-input w-36 py-0.5 text-xs" wire:model.live="who" aria-label="Filtrar por persona">
            <option value="">Todo el mundo</option>
            @foreach ($people as $person)
                <option value="{{ $person->id }}">{{ $person->displayName() }}</option>
            @endforeach
        </select>
    </div>

    <div class="max-h-[32rem] space-y-1 overflow-y-auto" data-roll-log>
        @forelse ($rolls as $roll)
            <div wire:key="roll-{{ $roll['id'] }}">@include('dice._roll', ['roll' => $roll, 'big' => false])</div>
        @empty
            <p class="text-xs text-[var(--pg-muted)]">Nadie ha tirado todavía. La bandeja 🎲 está abajo a la derecha.</p>
        @endforelse
    </div>
</section>
