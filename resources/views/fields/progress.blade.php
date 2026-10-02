{{--
    Barra de progreso con umbrales (experiencia): se escribe el valor y la
    barra muestra el avance dentro del tramo. Con los umbrales de 5e (0, 300,
    900…), @xp.level es el nivel que corresponde.
--}}
@php
    $derived = (array) data_get($sheet->computed, $key, []);
    $pct = max(0, min(100, (int) ($derived['pct'] ?? 0)));
    $k = \Illuminate\Support\Js::from($key);
    $hasThresholds = ! empty($field['config']['thresholds']);
@endphp
<div>
    <label class="pg-label" for="f-{{ $key }}">{{ $field['label'] }}</label>
    <input id="f-{{ $key }}" type="number" min="0" class="pg-input"
           wire:model.live.blur="data.{{ $key }}"
           @include('fields._bind')>

    @if ($hasThresholds)
        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--pg-shade)]" role="presentation">
            <div class="h-full rounded-full bg-[var(--pg-accent)] transition-[width]" style="width: {{ $pct }}%"
                 x-bind:style="{ width: Math.max(0, Math.min(100, comp({{ $k }}, 'pct') ?? 0)) + '%' }"></div>
        </div>
        <p class="mt-1 flex justify-between text-[0.7rem] text-[var(--pg-muted)]">
            <span>Nivel <span class="font-semibold text-[var(--pg-ink)]" x-text="comp({{ $k }}, 'level') ?? 0">{{ $derived['level'] ?? 0 }}</span></span>
            <span x-text="comp({{ $k }}, 'next') === null ? 'Máximo' : 'Siguiente: ' + comp({{ $k }}, 'next')">{{ ($derived['next'] ?? null) === null ? 'Máximo' : 'Siguiente: '.$derived['next'] }}</span>
        </p>
    @endif

    @include('fields._error')
    @include('fields._help')
</div>
