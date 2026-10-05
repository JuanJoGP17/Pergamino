{{--
    Bandeja de dados: botón flotante abajo a la derecha y, al abrirlo, el
    panel para tirar y el historial. Ver App\Livewire\DiceTray.
--}}
<div class="fixed bottom-4 right-4 z-40 flex flex-col items-end gap-2 print:hidden" data-dice-tray>
    @if ($open)
        <div class="w-[22rem] max-w-[calc(100vw-2rem)] space-y-3 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-3 shadow-xl">
            <div class="flex items-center justify-between">
                <h2 class="font-serif font-bold text-[var(--pg-accent)]">Dados</h2>
                <button type="button" wire:click="$set('open', false)" class="text-[var(--pg-muted)] hover:text-[var(--pg-accent)]" aria-label="Cerrar la bandeja">&times;</button>
            </div>

            @if ($last)
                @include('dice._roll', ['roll' => $last, 'big' => true])
            @endif

            <form wire:submit="roll" class="space-y-2">
                <div class="flex gap-2">
                    <input type="text" wire:model="expression" class="pg-input font-mono" aria-label="Tirada"
                           placeholder="1d20+5, 4d6kh3, 5d10>=7" autocomplete="off" spellcheck="false">
                    <button type="submit" class="pg-btn whitespace-nowrap">Tirar</button>
                </div>
                @if ($error)
                    <p class="text-xs text-[var(--pg-accent)]" role="alert">{{ $error }}</p>
                @endif

                <div class="flex overflow-hidden rounded-md border border-[var(--pg-border)] text-xs" role="radiogroup" aria-label="Modo">
                    @foreach (['normal' => 'Normal', 'advantage' => 'Ventaja', 'disadvantage' => 'Desventaja'] as $value => $label)
                        <label class="flex-1 cursor-pointer px-2 py-1 text-center {{ $mode === $value ? 'bg-[var(--pg-accent)] text-white' : '' }}">
                            <input type="radio" class="sr-only" wire:model.live="mode" value="{{ $value }}">{{ $label }}
                        </label>
                    @endforeach
                </div>

                @if ($campaigns->isNotEmpty())
                    <div class="flex items-center gap-2 text-xs">
                        <select class="pg-input py-1 text-xs" wire:model.live="campaign" aria-label="Mesa">
                            <option value="">Sin mesa (solo yo)</option>
                            @foreach ($campaigns as $c)
                                <option value="{{ $c->uuid }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                        @if ($isGm)
                            <label class="flex shrink-0 items-center gap-1" title="Solo la ves tú">
                                <input type="checkbox" wire:model="private" class="accent-[var(--pg-accent)]"> Secreta
                            </label>
                        @endif
                    </div>
                @endif
            </form>

            @if ($history->isNotEmpty())
                <details class="text-xs">
                    <summary class="cursor-pointer text-[var(--pg-muted)]">Mis últimas tiradas</summary>
                    <div class="mt-2 max-h-64 space-y-1 overflow-y-auto">
                        @foreach ($history as $roll)
                            <div wire:key="history-{{ $roll['id'] }}">@include('dice._roll', ['roll' => $roll, 'big' => false])</div>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    @endif

    <button type="button" wire:click="$toggle('open')"
            class="flex size-12 items-center justify-center rounded-full bg-[var(--pg-accent)] text-2xl text-white shadow-lg hover:brightness-110"
            aria-label="{{ $open ? 'Cerrar' : 'Abrir' }} la bandeja de dados" title="Dados">🎲</button>
</div>
