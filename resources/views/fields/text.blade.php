<div>
    <label class="pg-label" for="f-{{ $key }}">{{ $field['label'] }}</label>
    <input id="f-{{ $key }}" type="text" class="pg-input"
           wire:model.live.blur="data.{{ $key }}"
               @include('fields._bind')
           @if($field['config']['maxlength'] ?? null) maxlength="{{ $field['config']['maxlength'] }}" @endif
           placeholder="{{ $field['config']['placeholder'] ?? '' }}">
    @include('fields._help')
</div>
