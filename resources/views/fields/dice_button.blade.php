{{--
    Botón de tirada suelto: la tirada del campo («Tirada» en el inspector) con
    sus huecos resueltos. No guarda valor. Mayúsculas + clic = ventaja;
    Alt + clic = desventaja. Tira el servidor (ver _roll).
--}}
<div class="flex h-full items-end">
    <button type="button"
            title="Tirar · Mayús: ventaja · Alt: desventaja"
            x-on:click="$wire.rollField(@js($key), $event.shiftKey ? 'advantage' : ($event.altKey ? 'disadvantage' : 'normal'))"
            wire:loading.attr="disabled" wire:target="rollField"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-shade)] px-3 py-2 text-left hover:border-[var(--pg-accent)]">
        <span class="text-sm font-semibold">🎲 {{ $field['label'] }}</span>
        <span class="font-mono text-xs text-[var(--pg-muted)]" x-text="roll(@js($key))">{{ $rollExpression }}</span>
    </button>
    @include('fields._help')
</div>
