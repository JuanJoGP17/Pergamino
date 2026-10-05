{{--
    Editor de hoja: cabecera (nombre, plantilla, estado de guardado) y el
    cuerpo compartido con la vista previa del constructor (_body).
--}}
<div class="space-y-6">

    {{-- Cabecera --}}
    <div class="flex flex-wrap items-center gap-3">
        <input type="text"
               value="{{ $sheet->name }}"
               wire:change="rename($event.target.value)"
               class="flex-1 min-w-64 border-0 border-b border-transparent bg-transparent px-0 font-serif text-2xl font-bold text-[var(--pg-accent)] focus:border-[var(--pg-border)] focus:outline-none">

        <span class="text-xs text-[var(--pg-muted)]">
            {{ $schema->template['name'] ?? 'Plantilla' }}
            @if ($sheet->is_template_dirty)
                · <span class="text-[var(--pg-accent)]">hay una versión más nueva de la plantilla</span>
            @endif
        </span>

        @if ($campaigns->isNotEmpty())
            <label class="flex items-center gap-1 text-xs text-[var(--pg-muted)]">
                Tirar en
                <select class="pg-input w-auto py-1 text-xs" wire:model.live="rollCampaign">
                    <option value="">Sin mesa</option>
                    @foreach ($campaigns as $c)
                        <option value="{{ $c->uuid }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <div class="relative" x-data="{ open: false }">
            <button type="button" class="pg-btn-ghost py-1 text-xs" x-on:click="open = ! open">Imprimir / exportar</button>
            <div x-show="open" x-cloak x-on:click.outside="open = false"
                 class="absolute right-0 z-20 mt-2 w-56 space-y-1 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-2 text-sm shadow-lg">
                <a class="block rounded px-2 py-1 hover:bg-[var(--pg-shade)]" href="{{ route('sheets.print', $sheet) }}" target="_blank">Imprimir</a>
                <a class="block rounded px-2 py-1 hover:bg-[var(--pg-shade)]" href="{{ route('sheets.pdf', $sheet) }}" target="_blank">PDF</a>
                <a class="block rounded px-2 py-1 hover:bg-[var(--pg-shade)]" href="{{ route('sheets.pdf', $sheet) }}?marca=1" target="_blank">PDF con marca de agua</a>
                <a class="block rounded px-2 py-1 hover:bg-[var(--pg-shade)]" href="{{ route('sheets.export', $sheet) }}">Exportar JSON</a>
            </div>
        </div>

        @can('update', $sheet)
            {{-- Apariencia de esta hoja: acento y claro/oscuro sobre el tema de la plantilla. --}}
            <div class="relative" x-data="{ open: false }">
                <button type="button" class="pg-btn-ghost py-1 text-xs" x-on:click="open = ! open">Apariencia</button>
                <div x-show="open" x-cloak x-on:click.outside="open = false"
                     class="absolute right-0 z-20 mt-2 w-64 space-y-2 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-3 text-sm shadow-lg">
                    <label class="flex items-center justify-between gap-2">
                        <span>Color de acento</span>
                        <input type="color" class="h-7 w-12 cursor-pointer rounded border border-[var(--pg-border)] p-0.5"
                               value="{{ $sheetAccent ?? '#8b2e1f' }}" wire:model.live.change="sheetAccent">
                    </label>
                    <label class="block">
                        <span class="text-xs text-[var(--pg-muted)]">Claro / oscuro</span>
                        <select class="pg-input" wire:model.live="sheetMode">
                            <option value="">Como la plantilla</option>
                            <option value="auto">Según el dispositivo</option>
                            <option value="light">Siempre claro</option>
                            <option value="dark">Siempre oscuro</option>
                        </select>
                    </label>
                    <button type="button" class="text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]" wire:click="clearSheetTheme">Usar el de la plantilla</button>
                </div>
            </div>
        @endcan

        @foreach ($rests as $kind => $label)
            <button type="button" wire:click="rest('{{ $kind }}')"
                    wire:confirm="¿{{ $label }}? Se recuperan los recursos y usos que la plantilla marca."
                    class="pg-btn-ghost py-1 text-xs">{{ $label }}</button>
        @endforeach

        <span class="text-xs text-[var(--pg-muted)]" wire:loading.remove wire:target="save">
            @if ($savedAt) Guardado {{ $savedAt }} @endif
        </span>
        <span class="text-xs text-[var(--pg-muted)]" wire:loading wire:target="save">Guardando…</span>
    </div>

    @if ($notice)
        <p class="rounded-md border border-[var(--pg-accent)] bg-[var(--pg-surface)] px-3 py-2 text-sm text-[var(--pg-accent)]" role="alert">{{ $notice }}</p>
    @endif

    @if (session('sheet_notice'))
        <p class="rounded-md border border-[var(--pg-border)] bg-[var(--pg-surface)] px-3 py-2 text-sm" role="status">{{ session('sheet_notice') }}</p>
    @endif

    @include('livewire.sheet._body')
</div>
