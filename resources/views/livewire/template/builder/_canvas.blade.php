{{--
    Lienzo: pestañas → secciones → rejilla de 12 columnas con los campos.

    Los tres niveles se reordenan arrastrando (x-sortable, resources/js/builder):
      · pestañas, en la barra;
      · secciones, por su asa ⠿, dentro de la pestaña;
      · campos, dentro de su sección o hacia otra de la misma pestaña.
    Para llevar una sección o un campo a OTRA pestaña, el inspector tiene un
    selector «Pestaña» / «Sección».
--}}
@php
    // Literales para que Tailwind los genere (ver livewire/sheet/_body).
    $spans = [
        1 => 'sm:col-span-1',   2 => 'sm:col-span-2',   3 => 'sm:col-span-3',
        4 => 'sm:col-span-4',   5 => 'sm:col-span-5',   6 => 'sm:col-span-6',
        7 => 'sm:col-span-7',   8 => 'sm:col-span-8',   9 => 'sm:col-span-9',
        10 => 'sm:col-span-10', 11 => 'sm:col-span-11', 12 => 'sm:col-span-12',
    ];
    $selected = fn (string $kind, int $id) => $selection === $kind && $selectedId === $id;
@endphp

<div class="space-y-3">
    {{-- Pestañas --}}
    <div class="flex flex-wrap items-end gap-1 border-b border-[var(--pg-border)]">
        <nav class="flex flex-wrap gap-1" aria-label="Pestañas de la plantilla"
             x-sortable="{ group: 'tabs', item: 'tab', container: 0, filter: '.pg-no-drag',
                           move: (id, to, index) => $wire.moveTab(id, index) }">
            @foreach ($template->tabs as $tab)
                <button type="button" data-tab-id="{{ $tab->id }}" wire:key="tab-{{ $tab->id }}"
                        wire:click="selectTab({{ $tab->id }})"
                        class="-mb-px cursor-grab border-b-2 px-3 py-2 text-sm
                               {{ $currentTab?->id === $tab->id
                                  ? 'border-[var(--pg-accent)] font-semibold text-[var(--pg-accent)]'
                                  : 'border-transparent text-[var(--pg-muted)] hover:text-[var(--pg-ink)]' }}
                               {{ $selected('tab', $tab->id) ? 'bg-[var(--pg-shade)]' : '' }}">
                    {{ $tab->label }}
                </button>
            @endforeach
        </nav>
        <button type="button" wire:click="addTab" class="mb-1 ml-1 rounded px-2 py-1 text-sm text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">+ Pestaña</button>
    </div>

    {{-- Secciones de la pestaña actual --}}
    @if ($currentTab)
        <div class="space-y-3" wire:key="sections-of-{{ $currentTab->id }}"
             x-sortable="{ group: 'sections', item: 'section', container: {{ $currentTab->id }}, handle: '[data-handle]',
                           move: (id, to, index) => $wire.moveSection(id, to, index) }">
            @foreach ($currentTab->sections as $section)
                <div data-section-id="{{ $section->id }}" wire:key="section-{{ $section->id }}"
                     class="rounded-lg border bg-[var(--pg-surface)] p-3
                            {{ $selected('section', $section->id) ? 'border-[var(--pg-accent)] ring-2 ring-[var(--pg-accent)]/30' : 'border-[var(--pg-border)]' }}">

                    <div class="mb-2 flex items-center gap-2">
                        <span data-handle class="cursor-grab select-none text-[var(--pg-muted)]" title="Arrastra para reordenar la sección">⠿</span>
                        <button type="button" wire:click="select('section', {{ $section->id }})"
                                class="font-serif text-sm font-bold text-[var(--pg-accent)] hover:underline">
                            {{ $section->label ?: 'Sección sin título' }}
                        </button>
                        <span class="font-mono text-[0.65rem] text-[var(--pg-muted)]">{{ $section->key }}</span>
                        @if ($section->visible_if)
                            <span class="rounded bg-[var(--pg-shade)] px-1 text-[0.6rem] text-[var(--pg-muted)]" title="{{ $section->visible_if }}">condicional</span>
                        @endif
                    </div>

                    <div class="grid min-h-14 grid-cols-12 gap-2 rounded border border-dashed border-transparent"
                         wire:key="fields-of-{{ $section->id }}"
                         x-sortable="{ group: 'fields', item: 'field', container: {{ $section->id }},
                                       move: (id, to, index) => $wire.moveField(id, to, index),
                                       add: (type, to, index) => $wire.addFieldAt(type, to, index) }">
                        @foreach ($section->fields as $field)
                            <div data-field-id="{{ $field->id }}" wire:key="field-card-{{ $field->id }}"
                                 wire:click="select('field', {{ $field->id }})"
                                 class="col-span-12 {{ $spans[min(12, max(1, $field->col_span))] }} cursor-grab select-none rounded border bg-[var(--pg-bg)] px-2 py-1.5
                                        {{ $selected('field', $field->id) ? 'border-[var(--pg-accent)] ring-2 ring-[var(--pg-accent)]/30' : 'border-[var(--pg-border)] hover:border-[var(--pg-muted)]' }}">
                                <div class="flex items-baseline justify-between gap-1">
                                    <span class="truncate text-sm font-semibold">{{ $field->label }}</span>
                                    <span class="shrink-0 text-[0.6rem] uppercase text-[var(--pg-muted)]">{{ $field->definition()?->label() ?? $field->type }}</span>
                                </div>
                                <div class="truncate font-mono text-[0.65rem] text-[var(--pg-muted)]">
                                    {{ '@'.$field->key }}
                                    @if ($field->formula) = {{ $field->formula }} @endif
                                    @if ($field->config['mod_formula'] ?? null) · mod = {{ $field->config['mod_formula'] }} @endif
                                </div>
                                @if ($field->visible_if || $field->readonly_if)
                                    <div class="mt-0.5 flex gap-1">
                                        @if ($field->visible_if)<span class="rounded bg-[var(--pg-shade)] px-1 text-[0.6rem] text-[var(--pg-muted)]">visible si…</span>@endif
                                        @if ($field->readonly_if)<span class="rounded bg-[var(--pg-shade)] px-1 text-[0.6rem] text-[var(--pg-muted)]">bloqueado si…</span>@endif
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        @if ($section->fields->isEmpty())
                            <p class="col-span-12 self-center py-3 text-center text-xs text-[var(--pg-muted)]">
                                Sección vacía: arrastra aquí un tipo de la paleta.
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <button type="button" wire:click="addSection"
                class="w-full rounded-lg border border-dashed border-[var(--pg-border)] py-2 text-sm text-[var(--pg-muted)] hover:border-[var(--pg-accent)] hover:text-[var(--pg-accent)]">
            + Sección en «{{ $currentTab->label }}»
        </button>
    @endif
</div>
