{{--
    Una fila de competencia: nivel, nombre, bonificador total y ajuste manual.
    La usan `proficiency` (una fila) y `derived_list` (una por elemento).

    Variables: $levels, $rowPath (null para proficiency, la clave del elemento
    para derived_list), $rowLabel, $rowValue, $rowBonus.
--}}
@php
    $levelPath = $rowPath === null ? 'level' : $rowPath.'.level';
    $miscPath = $rowPath === null ? 'misc' : $rowPath.'.misc';
    $bonusPath = $rowPath === null ? 'bonus' : $rowPath.'.bonus';
    $k = \Illuminate\Support\Js::from($key);
@endphp
<div class="flex items-center gap-2 py-0.5">
    <select class="pg-input w-auto max-w-28 shrink-0 px-1 py-0.5 text-xs"
            wire:model.live="data.{{ $key }}.{{ $levelPath }}"
            @include('fields._bind', ['bindPath' => $levelPath])
            aria-label="Nivel de {{ $rowLabel }}">
        @foreach ($levels as $level)
            <option value="{{ $level['key'] }}">{{ $level['label'] }}</option>
        @endforeach
    </select>

    <span class="min-w-0 flex-1 truncate text-sm">{{ $rowLabel }}</span>

    <span class="w-9 shrink-0 text-right font-semibold text-[var(--pg-accent)]"
          x-text="modOf(comp({{ $k }}, {{ \Illuminate\Support\Js::from($bonusPath) }}))">{{ \App\Domain\Sheet\SheetCalculator::formatModifier($rowBonus) }}</span>

    <input type="number" class="pg-input w-12 shrink-0 px-1 py-0.5 text-center text-xs"
           wire:model.live.blur="data.{{ $key }}.{{ $miscPath }}"
           @include('fields._bind', ['bindPath' => $miscPath])
           title="Ajuste manual" aria-label="Ajuste de {{ $rowLabel }}">
</div>
