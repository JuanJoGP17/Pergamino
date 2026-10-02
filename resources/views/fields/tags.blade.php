{{--
    Etiquetas libres (rasgos, aspectos de FATE): se escriben y Enter las
    añade; la plantilla puede sugerir algunas.
--}}
@php
    $tags = (array) data_get($sheet->data, $key, []);
    $suggestions = $field['config']['suggestions'] ?? [];
    $k = \Illuminate\Support\Js::from($key);
@endphp
<div x-data="{ draft: '' }">
    <label class="pg-label" for="f-{{ $key }}">{{ $field['label'] }}</label>
    <div class="flex flex-wrap items-center gap-1 rounded border border-[var(--pg-border)] bg-[var(--pg-bg)] p-1">
        <template x-for="tag in (val({{ $k }}) || [])" x-bind:key="tag">
            <span class="inline-flex items-center gap-1 rounded bg-[var(--pg-shade)] px-2 py-0.5 text-xs">
                <span x-text="tag"></span>
                <button type="button" class="text-[var(--pg-muted)] hover:text-[var(--pg-accent)]"
                        x-on:click="toggle({{ $k }}, tag, false)"
                        x-bind:aria-label="'Quitar ' + tag"
                        @include('fields._bind', ['bindKey' => false])>&times;</button>
            </span>
        </template>
        {{-- Antes de que arranque Alpine, las etiquetas como texto. --}}
        <span x-show="false" class="text-xs">{{ implode(', ', $tags) }}</span>

        <input id="f-{{ $key }}" type="text" class="min-w-24 flex-1 border-0 bg-transparent px-1 py-0.5 text-sm focus:outline-none"
               x-model="draft" maxlength="60" list="f-{{ $key }}-sugerencias"
               x-on:keydown.enter.prevent="draft.trim() && toggle({{ $k }}, draft.trim(), true); draft = ''"
               x-on:keydown.comma.prevent="draft.trim() && toggle({{ $k }}, draft.trim(), true); draft = ''"
               placeholder="Escribe y pulsa Enter"
               @include('fields._bind', ['bindKey' => false])>
    </div>
    @if ($suggestions)
        <datalist id="f-{{ $key }}-sugerencias">
            @foreach ($suggestions as $suggestion)
                <option value="{{ $suggestion }}"></option>
            @endforeach
        </datalist>
    @endif
    @include('fields._help')
</div>
