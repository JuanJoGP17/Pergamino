{{--
    Botón de tirada de un campo, con los huecos de la plantilla ya resueltos:
    `1d20 + {@destreza.mod}` llega aquí como `1d20 + 2`, y el navegador lo
    actualiza en cada pulsación.

    Solo muestra la expresión resultante; el motor de dados que la ejecuta y la
    publica en el log de la mesa llega en la Fase 6.
--}}
@if (! empty($field['roll']))
    <button type="button"
            title="La tirada llega con el motor de dados (Fase 6)"
            class="rounded px-1 text-xs text-[var(--pg-muted)]"
            disabled>
        🎲 <span class="font-mono" x-text="roll(@js($key))">{{ $rollExpression }}</span>
    </button>
@endif
