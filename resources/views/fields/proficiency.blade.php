{{--
    Competencia: nivel (sin competencia / competente / experto…), bonificador
    total y ajuste manual. El total = base + bonificador del nivel + ajuste;
    la base y el bonificador de cada nivel son fórmulas de la plantilla.
--}}
<div>
    <div class="flex items-center justify-between gap-2">
        <span class="pg-label">{{ $field['label'] }}</span>
        @include('fields._roll')
    </div>
    @include('fields._proficiency_row', [
        'levels' => $field['config']['levels'] ?? [],
        'rowPath' => null,
        'rowLabel' => $field['label'],
        'rowBonus' => data_get($sheet->computed, $key.'.bonus'),
    ])
    @include('fields._error')
    @include('fields._help')
</div>
