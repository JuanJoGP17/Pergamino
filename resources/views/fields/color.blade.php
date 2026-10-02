<div>
    <label class="pg-label" for="f-{{ $key }}">{{ $field['label'] }}</label>
    <input id="f-{{ $key }}" type="color" class="h-9 w-full cursor-pointer rounded border border-[var(--pg-border)] bg-[var(--pg-bg)] p-0.5"
           wire:model.live="data.{{ $key }}"
           @include('fields._bind')>
    @include('fields._help')
</div>
