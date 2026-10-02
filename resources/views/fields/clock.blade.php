{{--
    Reloj de progreso (Blades in the Dark): un círculo partido en segmentos.
    Pulsar un segmento rellena hasta él; pulsar el último lleno lo vacía.
--}}
@php
    $segments = \App\Domain\Sheet\FieldValue::clockSegments($field['config'] ?? []);
    $filled = (int) data_get($sheet->data, $key, 0);
    $k = \Illuminate\Support\Js::from($key);

    // Cuña i del círculo de radio 46 centrado en (50,50), empezando arriba.
    $wedge = function (int $i) use ($segments): string {
        $point = fn (float $a) => round(50 + 46 * cos($a), 2).' '.round(50 + 46 * sin($a), 2);
        $from = 2 * M_PI * $i / $segments - M_PI / 2;
        $to = 2 * M_PI * ($i + 1) / $segments - M_PI / 2;

        return 'M50 50 L'.$point($from).' A46 46 0 0 1 '.$point($to).' Z';
    };
@endphp
<div class="flex flex-col items-center">
    <span class="pg-label">{{ $field['label'] }}</span>

    <svg viewBox="0 0 100 100" class="size-24" role="group" aria-label="{{ $field['label'] }}">
        @for ($i = 0; $i < $segments; $i++)
            <path d="{{ $wedge($i) }}" wire:key="{{ $key }}-seg-{{ $i }}"
                  class="pg-clock-seg {{ $i < $filled ? 'pg-clock-on' : '' }}"
                  x-bind:class="{ 'pg-clock-on': {{ $i }} < (val({{ $k }}) ?? 0) }"
                  x-on:click="readonly({{ $k }}) || tick({{ $k }}, {{ $i }})"
                  role="button" aria-label="Segmento {{ $i + 1 }}"></path>
        @endfor
    </svg>

    <span class="text-xs text-[var(--pg-muted)]"><span x-text="val({{ $k }}) ?? 0">{{ $filled }}</span>/{{ $segments }}</span>

    @include('fields._error')
    @include('fields._help')
</div>
