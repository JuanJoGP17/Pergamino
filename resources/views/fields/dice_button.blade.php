{{--
    Botón de tirada suelto: la tirada del campo («Tirada» en el inspector) con
    sus huecos resueltos. No guarda valor.

    El motor de dados que la ejecuta y la publica en la mesa llega en la
    Fase 6; hasta entonces el botón muestra la expresión pero no tira.
--}}
<div class="flex h-full items-end">
    <button type="button" disabled
            title="La tirada llega con el motor de dados (Fase 6)"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-shade)] px-3 py-2 text-left">
        <span class="text-sm font-semibold">🎲 {{ $field['label'] }}</span>
        <span class="font-mono text-xs text-[var(--pg-muted)]" x-text="roll(@js($key))">{{ $rollExpression }}</span>
    </button>
    @include('fields._help')
</div>
