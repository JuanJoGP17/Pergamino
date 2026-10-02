{{--
    Inspector: configuración de lo seleccionado. Cada input se guarda al salir
    de él (wire:model.live.blur → Builder::updatedForm), y los errores de
    validación salen debajo del input que los provocó.
--}}
@php
    // Nombres que no chocan con los parámetros de _input: un @include hereda
    // las variables de esta vista.
    $fieldType = $form['type'] ?? null;
    $lintOf = fn (string $slot) => $lint[$slot] ?? [];
@endphp

<aside class="space-y-3 self-start rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-3 lg:sticky lg:top-4"
       aria-label="Inspector">

    @if ($selection === 'template')
        <h2 class="font-serif font-bold text-[var(--pg-accent)]">Plantilla</h2>

        @include('livewire.template.builder._input', ['name' => 'name', 'label' => 'Nombre'])
        @include('livewire.template.builder._input', ['name' => 'game_line', 'label' => 'Línea de juego', 'placeholder' => 'D&D 5e, Vampiro, Casero…'])
        @include('livewire.template.builder._input', ['name' => 'tagline', 'label' => 'Descripción corta'])

        <div>
            <label class="pg-label" for="i-visibility">Visibilidad</label>
            <select id="i-visibility" class="pg-input" wire:model.live="form.visibility">
                <option value="private">Privada: solo tú</option>
                <option value="unlisted">Oculta: quien tenga el enlace</option>
                <option value="public">Pública: en el catálogo</option>
            </select>
        </div>

        <fieldset class="space-y-2 border-t border-[var(--pg-border)] pt-3">
            <legend class="pg-label">mod() — modificador de atributo</legend>
            <p class="text-[0.7rem] text-[var(--pg-muted)]">floor((valor − base) / divisor). En d20: base 10, divisor 2.</p>
            <div class="grid grid-cols-2 gap-2">
                @include('livewire.template.builder._input', ['name' => 'mod_base', 'label' => 'Base', 'inputType' => 'number'])
                @include('livewire.template.builder._input', ['name' => 'mod_divisor', 'label' => 'Divisor', 'inputType' => 'number'])
            </div>
        </fieldset>

        <fieldset class="space-y-2 border-t border-[var(--pg-border)] pt-3">
            <legend class="pg-label">prof() — competencia por nivel</legend>
            <p class="text-[0.7rem] text-[var(--pg-muted)]">base + floor((nivel − 1) / cada). En 5e: 2 y 4.</p>
            <div class="grid grid-cols-2 gap-2">
                @include('livewire.template.builder._input', ['name' => 'prof_base', 'label' => 'Base', 'inputType' => 'number'])
                @include('livewire.template.builder._input', ['name' => 'prof_step', 'label' => 'Cada (niveles)', 'inputType' => 'number'])
            </div>
        </fieldset>

        <div class="border-t border-[var(--pg-border)] pt-3">
            <label class="pg-label" for="i-lookups">Tablas de consulta — lookup()</label>
            <textarea id="i-lookups" rows="7" class="pg-input font-mono text-xs" wire:model.live.blur="form.lookups"
                      placeholder='{ "dados_golpe": { "Mago": 6, "Guerrero": 10 } }'></textarea>
            <p class="mt-1 text-[0.7rem] text-[var(--pg-muted)]">En una fórmula: <code class="font-mono">lookup("dados_golpe", @clase, 8)</code></p>
            @error('form.lookups') <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror
        </div>

    @elseif ($selection === 'tab')
        <h2 class="font-serif font-bold text-[var(--pg-accent)]">Pestaña</h2>
        @include('livewire.template.builder._input', ['name' => 'label', 'label' => 'Título'])
        @include('livewire.template.builder._input', ['name' => 'key', 'label' => 'Clave', 'mono' => true])
        @include('livewire.template.builder._delete', ['what' => 'la pestaña con todas sus secciones y campos'])

    @elseif ($selection === 'section')
        <h2 class="font-serif font-bold text-[var(--pg-accent)]">Sección</h2>
        @include('livewire.template.builder._input', ['name' => 'label', 'label' => 'Título'])
        @include('livewire.template.builder._input', ['name' => 'key', 'label' => 'Clave', 'mono' => true])
        @include('livewire.template.builder._input', ['name' => 'description', 'label' => 'Descripción'])

        <div>
            <label class="pg-label" for="i-tab_id">Pestaña</label>
            <select id="i-tab_id" class="pg-input" wire:model.live="form.tab_id">
                @foreach ($template->tabs as $t)
                    <option value="{{ $t->id }}">{{ $t->label }}</option>
                @endforeach
            </select>
        </div>

        @include('livewire.template.builder._input', [
            'name' => 'visible_if', 'label' => 'Visible solo si…', 'mono' => true,
            'placeholder' => '@clase in ["Mago", "Bardo"]',
        ])

        <fieldset class="space-y-2 border-t border-[var(--pg-border)] pt-3">
            <legend class="pg-label">Añadir en bloque</legend>
            <p class="text-[0.7rem] text-[var(--pg-muted)]">Un nombre por línea; todos del mismo tipo.</p>
            <select class="pg-input" wire:model="bulkType" aria-label="Tipo de los campos">
                @foreach ($types as $t)
                    <option value="{{ $t->value }}">{{ $t->label() }}</option>
                @endforeach
            </select>
            <textarea rows="5" class="pg-input" wire:model="bulkNames" aria-label="Nombres, uno por línea"
                      placeholder="Acrobacias&#10;Arcanos&#10;Atletismo"></textarea>
            <button type="button" wire:click="bulkAdd" class="pg-btn-ghost w-full">Añadir a esta sección</button>
        </fieldset>

        @include('livewire.template.builder._delete', ['what' => 'la sección con todos sus campos'])

    @elseif ($selection === 'field')
        <div class="flex items-center justify-between gap-2">
            <h2 class="font-serif font-bold text-[var(--pg-accent)]">Campo</h2>
            <div class="flex gap-3">
                <button type="button" wire:click="duplicateField({{ $selectedId }})" class="text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">Duplicar</button>
                <button type="button" wire:click="deleteSelected" wire:confirm="¿Borrar el campo?"
                        class="text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">Borrar</button>
            </div>
        </div>

        @include('livewire.template.builder._input', ['name' => 'label', 'label' => 'Etiqueta'])
        @include('livewire.template.builder._input', [
            'name' => 'key', 'label' => 'Clave (en fórmulas: @clave)', 'mono' => true,
            'help' => 'Si la cambias, las fórmulas que usaban la anterior quedarán rotas: el panel de validación te dirá cuáles.',
        ])

        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="pg-label" for="i-type">Tipo</label>
                <select id="i-type" class="pg-input" wire:model.live="form.type">
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="pg-label" for="i-section_id">Sección</label>
                <select id="i-section_id" class="pg-input" wire:model.live="form.section_id">
                    @foreach ($template->tabs as $t)
                        <optgroup label="{{ $t->label }}">
                            @foreach ($t->sections as $s)
                                <option value="{{ $s->id }}">{{ $s->label ?: $s->key }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="pg-label" for="i-col_span">Ancho: <span x-text="$wire.form.col_span">{{ $form['col_span'] ?? 12 }}</span> de 12 columnas</label>
            <input id="i-col_span" type="range" min="1" max="12" class="w-full accent-[var(--pg-accent)]" wire:model.live.change="form.col_span">
        </div>

        {{-- Configuración propia de cada tipo (§4) --}}
        @switch($fieldType)
            @case('text')
                <div class="grid grid-cols-2 gap-2">
                    @include('livewire.template.builder._input', ['name' => 'config.placeholder', 'label' => 'Texto de ejemplo'])
                    @include('livewire.template.builder._input', ['name' => 'config.maxlength', 'label' => 'Máx. caracteres', 'inputType' => 'number'])
                </div>
                @break

            @case('textarea')
                @include('livewire.template.builder._input', ['name' => 'config.rows', 'label' => 'Filas', 'inputType' => 'number'])
                @break

            @case('number')
                <div class="grid grid-cols-3 gap-2">
                    @include('livewire.template.builder._input', ['name' => 'config.min', 'label' => 'Mín.', 'inputType' => 'number'])
                    @include('livewire.template.builder._input', ['name' => 'config.max', 'label' => 'Máx.', 'inputType' => 'number'])
                    @include('livewire.template.builder._input', ['name' => 'config.step', 'label' => 'Paso', 'inputType' => 'number'])
                    @include('livewire.template.builder._input', ['name' => 'config.prefix', 'label' => 'Prefijo'])
                    @include('livewire.template.builder._input', ['name' => 'config.suffix', 'label' => 'Sufijo'])
                </div>
                @break

            @case('select')
                <div>
                    <label class="pg-label" for="i-config-options">Opciones, una por línea</label>
                    <textarea id="i-config-options" rows="5" class="pg-input" wire:model.live.blur="form.config.options"
                              placeholder="Mago&#10;Guerrero | Guerrera"></textarea>
                    <p class="mt-1 text-[0.7rem] text-[var(--pg-muted)]">«valor | etiqueta» si quieres mostrar otro texto.</p>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="form.config.allow_empty" class="accent-[var(--pg-accent)]"> Permitir dejarla vacía
                </label>
                @break

            @case('attribute')
                <div class="grid grid-cols-2 gap-2">
                    @include('livewire.template.builder._input', ['name' => 'config.min', 'label' => 'Mín.', 'inputType' => 'number'])
                    @include('livewire.template.builder._input', ['name' => 'config.max', 'label' => 'Máx.', 'inputType' => 'number'])
                </div>
                @include('livewire.template.builder._input', [
                    'name' => 'config.mod_formula', 'label' => 'Fórmula del modificador', 'mono' => true,
                    'placeholder' => 'vacío = mod(@self) con los ajustes de la plantilla',
                    'help' => '@self es este atributo. Ej.: floor((@self - 10) / 2)',
                    'problems' => $lintOf('mod_formula'),
                ])
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="form.config.show_mod" class="accent-[var(--pg-accent)]"> Mostrar el modificador
                </label>
                @break

            @case('computed')
                @include('livewire.template.builder._input', [
                    'name' => 'formula', 'label' => 'Fórmula', 'mono' => true, 'textarea' => true,
                    'placeholder' => '10 + @destreza.mod + if(@escudo, 2, 0)',
                    'problems' => $lintOf('formula'),
                ])
                <div>
                    <label class="pg-label" for="i-config-format">Formato</label>
                    <select id="i-config-format" class="pg-input" wire:model.live="form.config.format">
                        <option value="">Tal cual</option>
                        <option value="int">Número entero</option>
                        <option value="mod">Modificador (+3 / −1)</option>
                        <option value="percent">Porcentaje</option>
                        <option value="text">Texto</option>
                    </select>
                </div>
                @break

            @case('multiselect')
                @include('livewire.template.builder._input', [
                    'name' => 'config.options', 'label' => 'Opciones, una por línea', 'textarea' => true, 'textRows' => 5,
                    'placeholder' => "Común\nÉlfico | Élfico (escrito)",
                ])
                @include('livewire.template.builder._input', ['name' => 'config.max_selections', 'label' => 'Máximo de opciones marcadas (vacío = sin tope)', 'inputType' => 'number'])
                @break

            @case('tags')
                @include('livewire.template.builder._input', [
                    'name' => 'config.suggestions', 'label' => 'Sugerencias, una por línea', 'textarea' => true,
                    'placeholder' => "Valiente\nCurioso",
                ])
                @break

            @case('resource')
                @include('livewire.template.builder._input', [
                    'name' => 'config.max_formula', 'label' => 'Fórmula del máximo (opcional)', 'mono' => true,
                    'placeholder' => '@nivel * 8 + @constitucion.mod',
                    'help' => 'Vacía: el máximo se escribe en la hoja. En fórmulas: @clave.current, .max, .temp, .pct',
                ])
                <div class="grid grid-cols-2 gap-2">
                    @include('livewire.template.builder._input', ['name' => 'config.bar_color', 'label' => 'Color de la barra', 'placeholder' => '#8b2e1f', 'mono' => true])
                    @include('livewire.template.builder._rest')
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="form.config.show_temp" class="accent-[var(--pg-accent)]"> Puntos temporales</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="form.config.allow_overflow" class="accent-[var(--pg-accent)]"> Permitir pasar del máximo</label>
                @break

            @case('track')
                <div class="grid grid-cols-2 gap-2">
                    @include('livewire.template.builder._input', ['name' => 'config.boxes', 'label' => 'Casillas', 'inputType' => 'number'])
                    <div>
                        <label class="pg-label" for="i-config-shape">Forma</label>
                        <select id="i-config-shape" class="pg-input" wire:model.live="form.config.shape">
                            <option value="box">Cuadrado</option>
                            <option value="dot">Círculo</option>
                            <option value="pip">Rombo</option>
                        </select>
                    </div>
                </div>
                @include('livewire.template.builder._input', [
                    'name' => 'config.boxes_formula', 'label' => 'Casillas por fórmula (opcional, máx. 30)', 'mono' => true,
                    'placeholder' => '@resistencia + 3',
                ])
                @include('livewire.template.builder._input', [
                    'name' => 'config.states', 'label' => 'Estados de cada casilla, uno por línea', 'textarea' => true,
                    'placeholder' => "Superficial\nAgravado",
                    'help' => 'Pulsar una casilla la pasa al siguiente estado. En fórmulas: @clave.marked, @clave.boxes',
                ])
                @break

            @case('clock')
                @include('livewire.template.builder._input', ['name' => 'config.segments', 'label' => 'Segmentos (2 a 24)', 'inputType' => 'number'])
                @break

            @case('counter')
                <div class="grid grid-cols-3 gap-2">
                    @include('livewire.template.builder._input', ['name' => 'config.min', 'label' => 'Mín.', 'inputType' => 'number'])
                    @include('livewire.template.builder._input', ['name' => 'config.max', 'label' => 'Máx.', 'inputType' => 'number'])
                    @include('livewire.template.builder._input', ['name' => 'config.step', 'label' => 'Paso', 'inputType' => 'number'])
                </div>
                <div class="grid grid-cols-2 gap-2">
                    @include('livewire.template.builder._rest')
                    <div>
                        <label class="pg-label" for="i-config-reset_to">Al descansar vuelve a</label>
                        <select id="i-config-reset_to" class="pg-input" wire:model.live="form.config.reset_to">
                            <option value="">Su máximo</option>
                            <option value="min">Su mínimo</option>
                        </select>
                    </div>
                </div>
                @break

            @case('progress')
                @include('livewire.template.builder._input', [
                    'name' => 'config.thresholds', 'label' => 'Umbrales', 'mono' => true,
                    'placeholder' => '0, 300, 900, 2700, 6500',
                    'help' => 'Nivel = umbrales alcanzados. En fórmulas: @clave.level, .next, .pct',
                ])
                @break

            @case('currency')
                @include('livewire.template.builder._input', [
                    'name' => 'config.denominations', 'label' => 'Monedas: clave | nombre | valor', 'textarea' => true, 'mono' => true, 'textRows' => 4,
                    'placeholder' => "pc | Cobre | 1\npp | Plata | 10\npo | Oro | 100",
                    'help' => 'El valor es cuánto vale en la moneda de valor 1. @clave.total es la suma convertida.',
                ])
                @break

            @case('proficiency')
            @case('derived_list')
                @if ($fieldType === 'proficiency')
                    @include('livewire.template.builder._input', [
                        'name' => 'config.base', 'label' => 'Base', 'mono' => true,
                        'placeholder' => '@destreza.mod',
                    ])
                @else
                    @include('livewire.template.builder._input', [
                        'name' => 'config.items', 'label' => 'Elementos: clave | nombre | base', 'textarea' => true, 'mono' => true, 'textRows' => 8,
                        'placeholder' => "acrobacias | Acrobacias | @destreza.mod\nsigilo | Sigilo | @destreza.mod",
                        'help' => 'En fórmulas: @clave.sigilo.bonus, @clave.sigilo.level',
                    ])
                @endif
                @include('livewire.template.builder._input', [
                    'name' => 'config.levels', 'label' => 'Niveles: clave | nombre | bonificador', 'textarea' => true, 'mono' => true, 'textRows' => 4,
                    'placeholder' => "no | — | 0\ncomp | Competente | @bono_competencia\nexperto | Experto | @bono_competencia * 2",
                    'help' => 'Total = base + bonificador del nivel + ajuste manual.',
                ])
                @break

            @case('repeater')
                @include('livewire.template.builder._input', [
                    'name' => 'config.columns', 'label' => 'Columnas: clave | nombre | tipo', 'textarea' => true, 'mono' => true, 'textRows' => 6,
                    'placeholder' => "nombre | Objeto | texto\npeso | Peso | número\ncantidad | Cant. | número\ntipo | Tipo | lista: Arma, Armadura\nequipado | Equipado | casilla\ntotal | Total | = @row.peso * @row.cantidad",
                    'help' => 'Tipos: texto, número, casilla, lista: A, B… o «= fórmula», donde @row es la fila. Fuera: sum(@clave[*].total).',
                ])
                <div class="grid grid-cols-2 gap-2">
                    @include('livewire.template.builder._input', ['name' => 'config.min_rows', 'label' => 'Filas mínimas', 'inputType' => 'number'])
                    @include('livewire.template.builder._input', ['name' => 'config.max_rows', 'label' => 'Filas máximas', 'inputType' => 'number'])
                </div>
                @break

            @case('image')
                <div>
                    <label class="pg-label" for="i-config-aspect">Proporción</label>
                    <select id="i-config-aspect" class="pg-input" wire:model.live="form.config.aspect">
                        <option value="free">Libre</option>
                        <option value="square">Cuadrada</option>
                        <option value="portrait">Vertical (3:4)</option>
                        <option value="landscape">Apaisada (16:9)</option>
                    </select>
                </div>
                @break

            @case('portrait')
                <div>
                    <label class="pg-label" for="i-config-shape">Forma</label>
                    <select id="i-config-shape" class="pg-input" wire:model.live="form.config.shape">
                        <option value="rounded">Redondeada</option>
                        <option value="circle">Círculo</option>
                        <option value="square">Cuadrada</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="form.config.frame" class="accent-[var(--pg-accent)]"> Con marco</label>
                @break

            @case('dice_button')
                <p class="text-xs text-[var(--pg-muted)]">Escribe la tirada abajo, en «Fórmulas → Tirada». Hasta la Fase 6 el botón la muestra pero no tira.</p>
                @break
        @endswitch

        @foreach ($lintOf('config') as $problem)
            <p class="text-xs text-[var(--pg-accent)]">⚠ {{ $problem }}</p>
        @endforeach

        @if (in_array($fieldType, ['text', 'textarea', 'number', 'select', 'checkbox', 'attribute', 'counter', 'clock', 'progress', 'color'], true))
            @include('livewire.template.builder._input', ['name' => 'default_value', 'label' => 'Valor por defecto'])
        @endif

        @include('livewire.template.builder._input', ['name' => 'help_text', 'label' => 'Ayuda (se ve bajo el campo)'])

        <fieldset class="space-y-2 border-t border-[var(--pg-border)] pt-3">
            <legend class="pg-label">Fórmulas</legend>
            @include('livewire.template.builder._input', [
                'name' => 'roll_expression', 'label' => 'Tirada', 'mono' => true,
                'placeholder' => '1d20 + {@destreza.mod}',
                'problems' => $lintOf('roll_expression'),
            ])
            @include('livewire.template.builder._input', [
                'name' => 'visible_if', 'label' => 'Visible solo si…', 'mono' => true,
                'placeholder' => '@nivel >= 5',
                'problems' => $lintOf('visible_if'),
            ])
            @include('livewire.template.builder._input', [
                'name' => 'readonly_if', 'label' => 'Bloqueado si…', 'mono' => true,
                'placeholder' => '@progreso_por_hitos',
                'problems' => $lintOf('readonly_if'),
            ])
        </fieldset>

        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
            <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="form.is_summary" class="accent-[var(--pg-accent)]"> En el resumen de mesa</label>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="form.is_required" class="accent-[var(--pg-accent)]"> Obligatorio</label>
        </div>

        @include('livewire.template.builder._delete', ['what' => 'el campo'])

        @if ($knownKeys)
            <details class="border-t border-[var(--pg-border)] pt-2 text-xs">
                <summary class="cursor-pointer text-[var(--pg-muted)]">Claves disponibles para fórmulas</summary>
                <p class="mt-1 font-mono leading-relaxed text-[var(--pg-muted)]">{{ collect($knownKeys)->map(fn ($k) => '@'.$k)->implode('  ') }}</p>
            </details>
        @endif
    @endif
</aside>
