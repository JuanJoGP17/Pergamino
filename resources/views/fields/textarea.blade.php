<div>
    <label class="pg-label" for="f-{{ $key }}">{{ $field['label'] }}</label>
    <textarea id="f-{{ $key }}" class="pg-input"
              rows="{{ $field['config']['rows'] ?? 4 }}"
              wire:model.live.blur="data.{{ $key }}"
               @include('fields._bind')></textarea>
    @include('fields._help')
</div>
