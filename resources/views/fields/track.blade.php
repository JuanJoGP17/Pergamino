{{--
    Marcas (estrés de FATE, salud de Vampiro, desgaste): una fila de casillas
    que se pulsan. Cada casilla pasa por los estados de la plantilla:
    vacía → Superficial → Agravado → vacía.

    Si el número de casillas sale de una fórmula (Salud = Resistencia + 3), se
    pintan todas las posibles y se ocultan las que sobran.
--}}
@php
    $config = $field['config'] ?? [];
    $value = array_values((array) data_get($sheet->data, $key, []));
    $cap = \App\Domain\Sheet\FieldValue::trackLength($config);
    $boxes = (int) data_get($sheet->computed, $key.'.boxes', $cap);
    $states = array_values($config['states'] ?? ['Marcada']);
    $stateCount = max(1, count($states));
    $shape = $config['shape'] ?? 'box';
    $k = \Illuminate\Support\Js::from($key);

    // Un solo estado: la casilla se rellena. Varios: cada uno con su signo.
    $glyphs = $stateCount === 1 ? ['', ''] : ['', '╱', '✕', '■', '●', '★'];
    $titles = array_merge(['Vacía'], $states);
    $shapeClass = match ($shape) {
        'dot' => 'size-6 rounded-full',
        'pip' => 'size-4 rotate-45 rounded-[2px]',
        default => 'size-6 rounded',
    };
@endphp
<div>
    <div class="flex items-center justify-between gap-2">
        <span class="pg-label">{{ $field['label'] }}</span>
        <span class="text-xs text-[var(--pg-muted)]">
            <span x-text="comp({{ $k }}, 'marked') ?? 0">{{ data_get($sheet->computed, $key.'.marked', 0) }}</span>/<span x-text="comp({{ $k }}, 'boxes') ?? {{ $cap }}">{{ $boxes }}</span>
        </span>
    </div>

    <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="{{ $field['label'] }}">
        @for ($i = 0; $i < $cap; $i++)
            @php $state = (int) ($value[$i] ?? 0); @endphp
            <button type="button" wire:key="{{ $key }}-box-{{ $i }}"
                    class="pg-track-box {{ $shapeClass }} {{ $stateCount === 1 && $state > 0 ? 'pg-track-on' : '' }}"
                    style="{{ $i >= $boxes ? 'display: none' : '' }}"
                    x-show="{{ $i }} < (comp({{ $k }}, 'boxes') ?? {{ $cap }})"
                    x-bind:class="{ 'pg-track-on': {{ $stateCount }} === 1 && (val({{ $k }}, '{{ $i }}') ?? 0) > 0 }"
                    x-on:click="cycle({{ $k }}, {{ $i }}, {{ $stateCount }})"
                    title="{{ $titles[$state] ?? '' }}"
                    x-bind:title="{{ \Illuminate\Support\Js::from($titles) }}[val({{ $k }}, '{{ $i }}') ?? 0]"
                    aria-label="Casilla {{ $i + 1 }}"
                    @include('fields._bind', ['bindKey' => false])>
                <span class="pointer-events-none text-xs font-bold leading-none {{ $shape === 'pip' ? '-rotate-45' : '' }}"
                      x-text="{{ \Illuminate\Support\Js::from($glyphs) }}[val({{ $k }}, '{{ $i }}') ?? 0] ?? ''">{{ $glyphs[$state] ?? '' }}</span>
            </button>
        @endfor
    </div>

    @if ($stateCount > 1)
        <p class="mt-1 text-[0.65rem] text-[var(--pg-muted)]">
            {{ collect($states)->map(fn ($label, $i) => trim(($glyphs[$i + 1] ?? '').' '.$label))->implode(' · ') }}
        </p>
    @endif

    @include('fields._error')
    @include('fields._help')
</div>
