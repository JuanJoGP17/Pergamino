{{--
    Contador con −/+ (munición, usos de un poder). Respeta min y max; si la
    plantilla define reset_on, el descanso correspondiente lo devuelve a su
    sitio (ver App\Domain\Sheet\TakeRest).
--}}
@php
    $config = $field['config'] ?? [];
    $min = is_numeric($config['min'] ?? null) ? $config['min'] + 0 : 0;
    $max = is_numeric($config['max'] ?? null) ? $config['max'] + 0 : null;
    $step = max(1, (int) ($config['step'] ?? 1));
    $k = \Illuminate\Support\Js::from($key);
    $bounds = $min.', '.($max ?? 'null');
    $limits = array_filter(['min' => $min, 'max' => $max, 'step' => $step], fn ($v) => $v !== null);
@endphp
<div>
    <div class="flex items-center justify-between gap-2">
        <label class="pg-label" for="f-{{ $key }}">{{ $field['label'] }}</label>
        @include('fields._roll')
    </div>
    <div class="flex items-center gap-1">
        <button type="button" class="pg-step" aria-label="Restar a {{ $field['label'] }}"
                x-on:click="adjust({{ $k }}, null, -{{ $step }}, {{ $bounds }})"
                @include('fields._bind', ['bindKey' => false])>−</button>
        <input id="f-{{ $key }}" type="number" class="pg-input min-w-0 text-center font-semibold"
               wire:model.live.blur="data.{{ $key }}"
               @include('fields._bind')
               {{ new \Illuminate\View\ComponentAttributeBag($limits) }}>
        <button type="button" class="pg-step" aria-label="Sumar a {{ $field['label'] }}"
                x-on:click="adjust({{ $k }}, null, {{ $step }}, {{ $bounds }})"
                @include('fields._bind', ['bindKey' => false])>+</button>
        @if ($max !== null)
            <span class="shrink-0 text-xs text-[var(--pg-muted)]">/ {{ $max }}</span>
        @endif
    </div>
    @include('fields._error')
    @include('fields._help')
</div>
