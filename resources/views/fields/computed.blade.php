{{--
    Campo calculado: valor = fórmula. Solo lectura.

    El servidor pinta el valor guardado en `computed`; el navegador lo
    sustituye en cada pulsación con el mismo árbol. `config.format` decide cómo
    se presenta (§4: int | mod | percent | text).
--}}
@php
    $format = $field['config']['format'] ?? null;
    $value = data_get($sheet->computed, $key);
@endphp
<div>
    <span class="pg-label">{{ $field['label'] }}</span>
    <div class="flex items-center justify-between gap-2 rounded border border-[var(--pg-border)] bg-[var(--pg-shade)] px-2 py-1.5 text-sm font-semibold">
        <span x-text="display(@js($key))">{{ \App\Domain\Sheet\SheetCalculator::formatValue($value, $format) }}</span>
        @include('fields._roll')
    </div>
    @include('fields._error')
    @include('fields._help')
</div>
