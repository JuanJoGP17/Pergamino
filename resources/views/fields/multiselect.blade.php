{{--
    Selección múltiple: casillas, una por opción. El valor es la lista de las
    marcadas; con max_selections, las demás se bloquean al llegar al tope.
--}}
@php
    $config = $field['config'] ?? [];
    $selected = (array) data_get($sheet->data, $key, []);
    $max = (int) ($config['max_selections'] ?? 0);
    $k = \Illuminate\Support\Js::from($key);
@endphp
<fieldset>
    <legend class="pg-label">
        {{ $field['label'] }}
        @if ($max > 0)<span class="font-normal normal-case">(máx. {{ $max }})</span>@endif
    </legend>
    <div class="flex flex-wrap gap-x-4 gap-y-1">
        @foreach ($config['options'] ?? [] as $option)
            @php $v = \Illuminate\Support\Js::from($option['value']); @endphp
            <label class="flex items-center gap-1.5 text-sm" wire:key="{{ $key }}-{{ $loop->index }}">
                <input type="checkbox" class="size-4 accent-[var(--pg-accent)]"
                       @checked(in_array($option['value'], $selected, true))
                       x-bind:checked="(val({{ $k }}) || []).includes({{ $v }})"
                       x-on:change="toggle({{ $k }}, {{ $v }}, $event.target.checked, {{ $max }})"
                       @include('fields._bind', ['bindKey' => false])>
                {{ $option['label'] }}
            </label>
        @endforeach
    </div>
    @include('fields._help')
</fieldset>
