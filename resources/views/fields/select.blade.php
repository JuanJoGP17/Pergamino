<div>
    <label class="pg-label" for="f-{{ $key }}">{{ $field['label'] }}</label>
    <select id="f-{{ $key }}" class="pg-input" wire:model.live="data.{{ $key }}"
               @include('fields._bind')>
        @if($field['config']['allow_empty'] ?? true)
            <option value="">—</option>
        @endif
        @foreach ($field['config']['options'] ?? [] as $option)
            <option value="{{ $option['value'] }}">{{ $option['label'] ?? $option['value'] }}</option>
        @endforeach
    </select>
    @include('fields._help')
</div>
