<div>
    <label class="pg-label" for="f-{{ $key }}">{{ $field['label'] }}</label>
    <div class="flex items-center gap-1">
        @if($field['config']['prefix'] ?? null)
            <span class="text-xs text-[var(--pg-muted)]">{{ $field['config']['prefix'] }}</span>
        @endif
        <input id="f-{{ $key }}" type="number" class="pg-input"
               wire:model.live.blur="data.{{ $key }}"
               @include('fields._bind')
               @isset($field['config']['min']) min="{{ $field['config']['min'] }}" @endisset
               @isset($field['config']['max']) max="{{ $field['config']['max'] }}" @endisset
               step="{{ $field['config']['step'] ?? 1 }}">
        @if($field['config']['suffix'] ?? null)
            <span class="text-xs text-[var(--pg-muted)]">{{ $field['config']['suffix'] }}</span>
        @endif
        @include('fields._roll')
    </div>
    @include('fields._help')
</div>
