<div class="flex items-center gap-2 pt-5">
    <input id="f-{{ $key }}" type="checkbox" wire:model.live="data.{{ $key }}"
           @include('fields._bind')
           class="h-4 w-4 accent-[var(--pg-accent)]">
    <label for="f-{{ $key }}" class="text-sm">{{ $field['label'] }}</label>
    @include('fields._help')
</div>
