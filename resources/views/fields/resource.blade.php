{{--
    Recurso (PV, maná, cordura): actual / máximo (+ temporal) y una barra.

    Si el máximo sale de una fórmula (config.max_formula) no se escribe: se
    muestra el calculado. Los botones −/+ respetan el máximo salvo que la
    plantilla permita pasarse (allow_overflow).
--}}
@php
    $config = $field['config'] ?? [];
    $value = (array) data_get($sheet->data, $key, []);
    $derived = (array) data_get($sheet->computed, $key, []);
    $formulaMax = ! empty($config['max_formula']);
    $max = $derived['max'] ?? ($value['max'] ?? 0);
    $pct = max(0, min(100, (int) ($derived['pct'] ?? 0)));
    $k = \Illuminate\Support\Js::from($key);
    $cap = empty($config['allow_overflow']) ? "comp({$k}, 'max')" : 'null';
    $barStyle = ['width: '.$pct.'%'];
    if (! empty($config['bar_color'])) {
        $barStyle[] = 'background: '.$config['bar_color'];
    }
@endphp
<div class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-shade)] p-2">
    <div class="mb-1 flex items-center justify-between gap-2">
        <label class="pg-label mb-0" for="f-{{ $key }}">{{ $field['label'] }}</label>
        @include('fields._roll')
    </div>

    <div class="flex items-center gap-1">
        <button type="button" class="pg-step" aria-label="Restar 1 a {{ $field['label'] }}"
                x-on:click="adjust({{ $k }}, 'current', -1, 0, {{ $cap }})"
                @include('fields._bind', ['bindKey' => false])>−</button>

        <input id="f-{{ $key }}" type="number" class="pg-input min-w-14 flex-1 px-1 text-center font-serif text-lg font-bold"
               wire:model.live.blur="data.{{ $key }}.current"
               @include('fields._bind', ['bindPath' => 'current'])
               aria-label="{{ $field['label'] }} actual">

        <button type="button" class="pg-step" aria-label="Sumar 1 a {{ $field['label'] }}"
                x-on:click="adjust({{ $k }}, 'current', 1, 0, {{ $cap }})"
                @include('fields._bind', ['bindKey' => false])>+</button>

        <span class="px-1 text-[var(--pg-muted)]">/</span>

        @if ($formulaMax)
            <span class="min-w-8 text-center font-serif text-lg font-bold" title="{{ $config['max_formula'] }}"
                  x-text="comp({{ $k }}, 'max') ?? '—'">{{ $max }}</span>
        @else
            <input type="number" min="0" class="pg-input w-14 shrink-0 px-1 text-center"
                   wire:model.live.blur="data.{{ $key }}.max"
                   @include('fields._bind', ['bindPath' => 'max'])
                   aria-label="{{ $field['label'] }} máximo">
        @endif

        @if ($config['show_temp'] ?? true)
            <span class="pl-1 text-xs text-[var(--pg-muted)]" title="Temporales">+</span>
            <input type="number" min="0" class="pg-input w-12 shrink-0 px-1 text-center"
                   wire:model.live.blur="data.{{ $key }}.temp"
                   @include('fields._bind', ['bindPath' => 'temp'])
                   title="Temporales" aria-label="{{ $field['label'] }} temporales">
        @endif
    </div>

    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--pg-surface)]" role="presentation">
        <div class="h-full rounded-full transition-[width] {{ empty($config['bar_color']) ? 'bg-[var(--pg-accent)]' : '' }}"
             style="{{ implode('; ', $barStyle) }}"
             x-bind:style="{ width: Math.max(0, Math.min(100, comp({{ $k }}, 'pct') ?? 0)) + '%' }"></div>
    </div>

    @include('fields._error')
    @include('fields._help')
</div>
