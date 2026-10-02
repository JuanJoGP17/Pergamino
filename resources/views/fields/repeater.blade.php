{{--
    Tabla de filas (ataques, inventario, conjuros): columnas con tipo y
    columnas calculadas fila a fila (`= @row.peso * @row.cantidad`).

    Las celdas editables usan wire:model sobre data.clave.fila.columna; las
    calculadas se pintan desde computed y el navegador las recalcula al teclear.
    Añadir o quitar filas manda la lista entera: el servidor la sanea (tope de
    filas, solo columnas declaradas) y devuelve lo que quedó.
--}}
@php
    $config = $field['config'] ?? [];
    $columns = $config['columns'] ?? [];
    $rows = array_values((array) data_get($sheet->data, $key, []));
    $derivedRows = (array) data_get($sheet->computed, $key, []);
    $maxRows = (int) ($config['max_rows'] ?? 0);
    $minRows = (int) ($config['min_rows'] ?? 0);
    $k = \Illuminate\Support\Js::from($key);
@endphp
<div>
    <div class="flex items-center justify-between gap-2">
        <span class="pg-label">{{ $field['label'] }}</span>
        @include('fields._roll')
    </div>

    <div class="overflow-x-auto rounded border border-[var(--pg-border)]">
        <table class="w-full text-sm">
            <thead class="bg-[var(--pg-shade)] text-left text-[0.65rem] uppercase tracking-wide text-[var(--pg-muted)]">
                <tr>
                    @foreach ($columns as $column)
                        <th class="px-2 py-1 font-semibold {{ in_array($column['type'], ['number', 'computed', 'checkbox'], true) ? 'text-center' : '' }}">{{ $column['label'] }}</th>
                    @endforeach
                    <th class="w-8"><span class="sr-only">Quitar</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--pg-border)]">
                @forelse ($rows as $i => $row)
                    <tr wire:key="{{ $key }}-row-{{ $i }}-{{ count($rows) }}">
                        @foreach ($columns as $column)
                            @php $path = $i.'.'.$column['key']; @endphp
                            <td class="px-1 py-0.5 {{ $column['type'] === 'checkbox' ? 'text-center' : '' }}">
                                @switch($column['type'])
                                    @case('computed')
                                        <span class="block text-center font-semibold"
                                              x-text="fmt(comp({{ $k }}, {{ \Illuminate\Support\Js::from($path) }}))">{{ \App\Domain\Sheet\SheetCalculator::formatValue($derivedRows[$i][$column['key']] ?? null) }}</span>
                                        @break
                                    @case('checkbox')
                                        <input type="checkbox" class="size-4 accent-[var(--pg-accent)]"
                                               wire:model.live="data.{{ $key }}.{{ $path }}"
                                               @include('fields._bind', ['bindPath' => $path])
                                               aria-label="{{ $column['label'] }}, fila {{ $i + 1 }}">
                                        @break
                                    @case('select')
                                        <select class="pg-input px-1 py-0.5"
                                                wire:model.live="data.{{ $key }}.{{ $path }}"
                                                @include('fields._bind', ['bindPath' => $path])
                                                aria-label="{{ $column['label'] }}, fila {{ $i + 1 }}">
                                            <option value="">—</option>
                                            @foreach ($column['options'] ?? [] as $option)
                                                <option value="{{ $option }}">{{ $option }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @default
                                        <input type="{{ $column['type'] === 'number' ? 'number' : 'text' }}"
                                               class="pg-input px-1 py-0.5 {{ $column['type'] === 'number' ? 'text-center' : '' }}"
                                               wire:model.live.blur="data.{{ $key }}.{{ $path }}"
                                               @include('fields._bind', ['bindPath' => $path])
                                               aria-label="{{ $column['label'] }}, fila {{ $i + 1 }}">
                                @endswitch
                            </td>
                        @endforeach
                        <td class="px-1 text-center">
                            @if (count($rows) > $minRows)
                                <button type="button" class="rounded px-1 text-[var(--pg-muted)] hover:text-[var(--pg-accent)]"
                                        x-on:click="removeRow({{ $k }}, {{ $i }})"
                                        @include('fields._bind', ['bindKey' => false])
                                        title="Quitar la fila" aria-label="Quitar la fila {{ $i + 1 }}">&times;</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) + 1 }}" class="px-2 py-3 text-center text-xs text-[var(--pg-muted)]">Sin filas todavía.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($maxRows === 0 || count($rows) < $maxRows)
        <button type="button" class="mt-1 text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]"
                x-on:click="addRow({{ $k }}, {{ $maxRows }})"
                @include('fields._bind', ['bindKey' => false])>+ Añadir fila</button>
    @endif

    @include('fields._error')
    @include('fields._help')
</div>
