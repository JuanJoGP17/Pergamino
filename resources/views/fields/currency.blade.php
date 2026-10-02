{{--
    Monedas: una casilla por denominación y el total convertido a la moneda de
    valor 1 (@bolsa.total). El cambio entre monedas lo decide la plantilla.
--}}
@php
    $denominations = $field['config']['denominations'] ?? [];
    $base = collect($denominations)->first(fn ($d) => (float) ($d['rate'] ?? 0) === 1.0);
    $total = data_get($sheet->computed, $key.'.total', 0);
    $k = \Illuminate\Support\Js::from($key);
@endphp
<div>
    <span class="pg-label">{{ $field['label'] }}</span>
    <div class="grid grid-cols-[repeat(auto-fit,minmax(4.5rem,1fr))] gap-2">
        @foreach ($denominations as $d)
            <label class="block" wire:key="{{ $key }}-{{ $d['key'] }}">
                <span class="block text-[0.65rem] uppercase tracking-wide text-[var(--pg-muted)]">{{ $d['label'] }}</span>
                <input type="number" min="0" class="pg-input text-center"
                       wire:model.live.blur="data.{{ $key }}.{{ $d['key'] }}"
                       @include('fields._bind', ['bindPath' => $d['key']])>
            </label>
        @endforeach
    </div>
    @if ($base && count($denominations) > 1)
        <p class="mt-1 text-right text-[0.7rem] text-[var(--pg-muted)]">
            Total: <span class="font-semibold text-[var(--pg-ink)]" x-text="comp({{ $k }}, 'total') ?? 0">{{ \App\Domain\Formula\Value::toString($total) }}</span> {{ mb_strtolower($base['label']) }}
        </p>
    @endif
    @include('fields._error')
    @include('fields._help')
</div>
