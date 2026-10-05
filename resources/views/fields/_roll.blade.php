{{--
    Botón de tirada de un campo, con los huecos de la plantilla ya resueltos:
    `1d20 + {@destreza.mod}` llega aquí como `1d20 + 2`, y el navegador lo
    actualiza en cada pulsación.

    Al pulsarlo tira el SERVIDOR (rollField), con la expresión que él mismo
    resuelve de los datos guardados: el navegador no decide qué se tira.
    Mayúsculas + clic = ventaja; Alt + clic = desventaja.
--}}
@if (! empty($field['roll']))
    <button type="button"
            title="Tirar · Mayús: ventaja · Alt: desventaja"
            class="rounded px-1 text-xs text-[var(--pg-muted)] hover:bg-[var(--pg-shade)] hover:text-[var(--pg-accent)]"
            x-on:click="$wire.rollField(@js($key), $event.shiftKey ? 'advantage' : ($event.altKey ? 'disadvantage' : 'normal'))"
            wire:loading.attr="disabled" wire:target="rollField">
        🎲 <span class="font-mono" x-text="roll(@js($key))">{{ $rollExpression }}</span>
    </button>
@endif
