{{--
    Cuerpo de una hoja: pestañas y secciones con sus campos. No sabe nada de
    ningún sistema de juego: recorre el compiled_schema y delega cada campo a su
    parcial en resources/views/fields.

    Lo usan el editor de hojas y la vista previa del constructor, con los datos
    que prepara App\Domain\Sheet\SheetView. El componente Livewire que lo
    incluya debe tener `data` (los valores) y `selectTab()`.

    Dos capas de cálculo:
      · Servidor (autoritativo): pinta el estado inicial y recalcula al guardar.
      · Navegador (sheetFormulas, resources/js/formula/sheet.js): recalcula en
        cada pulsación con los mismos árboles. Por eso los valores derivados se
        pintan dos veces: el texto del servidor y un x-text que lo sustituye.
--}}
<div class="space-y-6" data-sheet="{{ $sheet->uuid }}"
     x-data="sheetFormulas(@js($clientSchema))"
     x-on:input="onInput($event)"
     x-on:change="onInput($event)">

    @if (count($schema->visibleTabs()) > 1)
        <nav class="flex flex-wrap gap-1 border-b border-[var(--pg-border)]">
            @foreach ($schema->visibleTabs() as $t)
                <button type="button" wire:click="selectTab('{{ $t['key'] }}')"
                        class="-mb-px border-b-2 px-3 py-2 text-sm
                               {{ ($currentTab['key'] ?? null) === $t['key']
                                  ? 'border-[var(--pg-accent)] font-semibold text-[var(--pg-accent)]'
                                  : 'border-transparent text-[var(--pg-muted)] hover:text-[var(--pg-ink)]' }}">
                    {{ $t['label'] }}
                </button>
            @endforeach
        </nav>
    @endif

    @if (! $currentTab)
        <p class="text-sm text-[var(--pg-muted)]">Esta plantilla todavía no tiene campos.</p>
    @else
        @foreach ($currentTab['sections'] as $section)
            <section class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4"
                     wire:key="section-{{ $section['key'] }}"
                     @if (! empty($section['visible_ast']))
                         x-show="sectionVisible(@js($section['key']))"
                         @style(['display: none' => ! ($sectionVisible[$section['key']] ?? true)])
                     @endif>
                @if ($section['label'])
                    <h2 class="mb-1 font-serif text-base font-bold text-[var(--pg-accent)]">
                        {{ $section['label'] }}
                    </h2>
                @endif

                @if ($section['description'])
                    <p class="mb-3 text-xs text-[var(--pg-muted)]">{{ $section['description'] }}</p>
                @endif

                @php
                    // Los nombres de clase de Tailwind deben aparecer literales en
                    // el código fuente para que el compilador los detecte; una
                    // interpolación tipo sm:col-span-{{ $n }} no se generaría.
                    $spans = [
                        1 => 'sm:col-span-1',   2 => 'sm:col-span-2',   3 => 'sm:col-span-3',
                        4 => 'sm:col-span-4',   5 => 'sm:col-span-5',   6 => 'sm:col-span-6',
                        7 => 'sm:col-span-7',   8 => 'sm:col-span-8',   9 => 'sm:col-span-9',
                        10 => 'sm:col-span-10', 11 => 'sm:col-span-11', 12 => 'sm:col-span-12',
                    ];
                @endphp

                <div class="grid grid-cols-12 gap-3">
                    @foreach ($section['field_keys'] as $key)
                        @php $field = $schema->field($key); @endphp
                        @continue(! $field)

                        <div class="col-span-12 {{ $spans[min(12, max(1, (int) $field['col_span']))] }}"
                             wire:key="field-{{ $key }}"
                             @if (! empty($field['visible_ast']))
                                 x-show="visible(@js($key))"
                                 @style(['display: none' => ! ($visible[$key] ?? true)])
                             @endif>
                            @include('fields.dispatch', [
                                'field' => $field,
                                'key'   => $key,
                                'sheet' => $sheet,
                                'isReadonly' => $readonly[$key] ?? false,
                                'rollExpression' => $rolls[$key] ?? null,
                                'formulaError' => $formulaErrors[$key] ?? null,
                            ])
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
</div>
