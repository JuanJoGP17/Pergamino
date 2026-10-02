{{--
    Atributo: valor grande editable con su modificador derivado debajo.
    El modificador NO se guarda en `data`; lo calcula SheetCalculator (con
    config.mod_formula si la plantilla la define) y vive en `computed`.
--}}
@php
    $mod = data_get($sheet->computed, $key.'.mod');
    $showMod = $field['config']['show_mod'] ?? true;
@endphp

<div class="flex flex-col items-center rounded-lg border border-[var(--pg-border)] bg-[var(--pg-shade)] px-2 py-2">
    <label for="f-{{ $key }}" class="text-[0.65rem] font-semibold uppercase tracking-wide text-[var(--pg-muted)]">
        {{ $field['label'] }}
    </label>

    <input id="f-{{ $key }}" type="number"
           class="w-full border-0 bg-transparent text-center font-serif text-2xl font-bold text-[var(--pg-ink)] focus:outline-none"
           wire:model.live.blur="data.{{ $key }}"
           @include('fields._bind')
           min="{{ $field['config']['min'] ?? 0 }}"
           max="{{ $field['config']['max'] ?? 99 }}">

    @if ($showMod)
        <span class="rounded bg-[var(--pg-surface)] px-2 text-sm font-semibold text-[var(--pg-accent)]"
              x-text="modText(@js($key))">{{ \App\Domain\Sheet\SheetCalculator::formatModifier($mod) }}</span>
    @endif

    @include('fields._roll')
    @include('fields._error')
    @include('fields._help')
</div>
