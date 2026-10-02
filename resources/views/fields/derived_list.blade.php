{{--
    Lista derivada: una lista fija de elementos con competencia, cada uno con
    su base. Las 18 habilidades de 5e en un solo campo:
    @habilidades.sigilo.bonus, @habilidades.sigilo.level.
--}}
<div>
    <span class="pg-label">{{ $field['label'] }}</span>
    <div class="divide-y divide-[var(--pg-border)]">
        @foreach ($field['config']['items'] ?? [] as $item)
            <div wire:key="{{ $key }}-{{ $item['key'] }}">
                @include('fields._proficiency_row', [
                    'levels' => $field['config']['levels'] ?? [],
                    'rowPath' => $item['key'],
                    'rowLabel' => $item['label'],
                    'rowBonus' => data_get($sheet->computed, $key.'.'.$item['key'].'.bonus'),
                ])
            </div>
        @endforeach
    </div>
    @include('fields._error')
    @include('fields._help')
</div>
