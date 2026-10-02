{{--
    Editor de apariencia (§6.3): controles a la izquierda y, a la derecha, la
    hoja real de la plantilla con el tema aplicado (la misma vista previa que
    el constructor). Cada control guarda al cambiar.
--}}
@php
    $colorLabels = [
        'bg' => 'Fondo', 'surface' => 'Secciones', 'ink' => 'Texto', 'accent' => 'Acento',
        'muted' => 'Texto suave', 'border' => 'Bordes', 'shade' => 'Campos',
    ];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] px-4 py-3">
        <div class="min-w-48 flex-1">
            <a href="{{ route('templates.builder', $template) }}" wire:navigate class="text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">← Constructor</a>
            <h1 class="font-serif text-xl font-bold text-[var(--pg-accent)]">Apariencia de «{{ $template->name }}»</h1>
            <p class="text-xs text-[var(--pg-muted)]">
                Se aplica al momento a todas las hojas de la plantilla, sin publicar.
                @if ($saved) <span class="font-semibold">Guardado {{ $saved }}</span> @endif
            </p>
        </div>
        <button type="button" class="pg-btn-ghost text-sm" wire:click="resetTheme"
                wire:confirm="¿Volver al tema por defecto? Se pierden los cambios de apariencia.">Restablecer</button>
    </div>

    <div class="grid gap-4 lg:grid-cols-[22rem_minmax(0,1fr)]">
        <aside class="space-y-5 self-start rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4 text-sm lg:sticky lg:top-4" aria-label="Controles de apariencia">

            {{-- Presets --}}
            <section>
                <h2 class="pg-label">Punto de partida</h2>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($presets as $key => $preset)
                        <button type="button" wire:click="choosePreset('{{ $key }}')" wire:key="preset-{{ $key }}"
                                data-preset="{{ $key }}"
                                class="rounded-md border p-2 text-left text-xs {{ ($form['preset'] ?? '') === $key ? 'border-[var(--pg-accent)] ring-2 ring-[var(--pg-accent)]/30' : 'border-[var(--pg-border)] hover:border-[var(--pg-muted)]' }}">
                            <span class="mb-1 flex h-5 overflow-hidden rounded">
                                @foreach (['bg', 'surface', 'accent', 'ink'] as $c)
                                    <span class="flex-1" style="background: {{ $preset[$preset['mode'] === 'dark' ? 'colors_dark' : 'colors'][$c] }}"></span>
                                @endforeach
                            </span>
                            {{ $preset['label'] }}
                        </button>
                    @endforeach
                </div>
            </section>

            <section>
                <label class="pg-label" for="a-mode">Claro / oscuro</label>
                <select id="a-mode" class="pg-input" wire:model.live="form.mode">
                    <option value="auto">Según el dispositivo</option>
                    <option value="light">Siempre claro</option>
                    <option value="dark">Siempre oscuro</option>
                </select>
            </section>

            {{-- Colores --}}
            <section>
                <h2 class="pg-label">Colores</h2>
                <div class="grid grid-cols-[1fr_auto_auto] items-center gap-x-3 gap-y-1">
                    <span></span>
                    <span class="text-[0.65rem] text-[var(--pg-muted)]">Claro</span>
                    <span class="text-[0.65rem] text-[var(--pg-muted)]">Oscuro</span>
                    @foreach ($colorLabels as $c => $label)
                        <span class="text-xs">{{ $label }}</span>
                        @foreach (['colors', 'colors_dark'] as $palette)
                            <input type="color" class="h-7 w-10 cursor-pointer rounded border border-[var(--pg-border)] bg-transparent p-0.5"
                                   wire:model.live.change="form.{{ $palette }}.{{ $c }}"
                                   aria-label="{{ $label }} ({{ $palette === 'colors' ? 'claro' : 'oscuro' }})">
                        @endforeach
                    @endforeach
                </div>
            </section>

            {{-- Tipografía --}}
            <section class="space-y-2">
                <h2 class="pg-label">Tipografía</h2>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['heading' => 'Títulos', 'body' => 'Texto'] as $slot => $label)
                        <div>
                            <label class="text-xs" for="a-font-{{ $slot }}">{{ $label }}</label>
                            <select id="a-font-{{ $slot }}" class="pg-input" wire:model.live="form.typography.{{ $slot }}">
                                @foreach ($fonts as $fontKey => $fontLabel)
                                    <option value="{{ $fontKey }}">{{ $fontLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
                <div>
                    <label class="text-xs" for="a-scale">Tamaño: {{ number_format((float) ($form['typography']['scale'] ?? 1) * 100) }} %</label>
                    <input id="a-scale" type="range" min="0.85" max="1.3" step="0.05" class="w-full accent-[var(--pg-accent)]"
                           wire:model.live.change="form.typography.scale">
                </div>
                <div>
                    <label class="text-xs" for="a-transform">Títulos en</label>
                    <select id="a-transform" class="pg-input" wire:model.live="form.typography.heading_transform">
                        <option value="none">Normal</option>
                        <option value="uppercase">MAYÚSCULAS</option>
                        <option value="small-caps">Versalitas</option>
                    </select>
                </div>
            </section>

            {{-- Superficie --}}
            <section class="space-y-2">
                <h2 class="pg-label">Superficie</h2>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs" for="a-texture">Textura</label>
                        <select id="a-texture" class="pg-input" wire:model.live="form.surface.texture">
                            <option value="none">Ninguna</option>
                            <option value="parchment">Pergamino</option>
                            <option value="paper">Papel</option>
                            <option value="grid">Cuadrícula</option>
                            <option value="scanlines">Líneas de monitor</option>
                            <option value="dots">Trama de puntos</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs" for="a-border">Bordes</label>
                        <select id="a-border" class="pg-input" wire:model.live="form.surface.border_style">
                            <option value="solid">Línea</option>
                            <option value="double">Doble</option>
                            <option value="dashed">Discontinuo</option>
                            <option value="thick">Grueso</option>
                            <option value="ornate">Ornamentado</option>
                            <option value="none">Sin borde</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs" for="a-shadow">Sombra</label>
                        <select id="a-shadow" class="pg-input" wire:model.live="form.surface.shadow">
                            <option value="none">Ninguna</option>
                            <option value="soft">Suave</option>
                            <option value="hard">Dura (cómic)</option>
                            <option value="glow">Resplandor</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs" for="a-density">Densidad</label>
                        <select id="a-density" class="pg-input" wire:model.live="form.layout.density">
                            <option value="compact">Compacta</option>
                            <option value="comfortable">Cómoda</option>
                            <option value="spacious">Amplia</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="text-xs" for="a-radius">Esquinas: {{ $form['surface']['corner_radius'] ?? 8 }} px</label>
                    <input id="a-radius" type="range" min="0" max="24" step="1" class="w-full accent-[var(--pg-accent)]"
                           wire:model.live.change="form.surface.corner_radius">
                </div>
                <div>
                    <label class="text-xs" for="a-width">Ancho máximo: {{ $form['layout']['max_width'] ?? 1100 }} px</label>
                    <input id="a-width" type="range" min="640" max="1600" step="20" class="w-full accent-[var(--pg-accent)]"
                           wire:model.live.change="form.layout.max_width">
                </div>
            </section>

            {{-- Fondo propio --}}
            <section class="space-y-2">
                <h2 class="pg-label">Imagen de fondo</h2>
                <div class="flex items-center gap-3 text-xs">
                    <label class="cursor-pointer text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">
                        {{ ! empty($form['custom_background']['media_id']) ? 'Cambiar imagen' : 'Subir imagen' }}
                        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="sr-only" wire:model="background">
                    </label>
                    @if (! empty($form['custom_background']['media_id']))
                        <button type="button" class="text-[var(--pg-muted)] hover:text-[var(--pg-accent)]" wire:click="removeBackground">Quitar</button>
                    @endif
                    <span wire:loading wire:target="background">Subiendo…</span>
                </div>
                @error('background') <p class="text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror
                @if (! empty($form['custom_background']['media_id']))
                    <div>
                        <label class="text-xs" for="a-bg-opacity">Opacidad: {{ number_format((float) ($form['custom_background']['opacity'] ?? 0.15) * 100) }} %</label>
                        <input id="a-bg-opacity" type="range" min="0" max="1" step="0.05" class="w-full accent-[var(--pg-accent)]"
                               wire:model.live.change="form.custom_background.opacity">
                    </div>
                    <select class="pg-input" wire:model.live="form.custom_background.repeat" aria-label="Cómo se coloca la imagen">
                        <option value="cover">Cubrir la hoja</option>
                        <option value="tile">En mosaico</option>
                    </select>
                @endif
            </section>
        </aside>

        <div class="min-w-0">
            <livewire:template.preview :template-uuid="$templateUuid" :key="'appearance-preview-'.$templateUuid" />
        </div>
    </div>
</div>
