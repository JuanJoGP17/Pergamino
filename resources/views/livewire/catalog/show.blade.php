<div class="space-y-5">
    <a href="{{ route('catalog.index') }}" wire:navigate class="text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">← Catálogo</a>

    @if ($notice)
        <p role="status" class="rounded-md border px-3 py-2 text-sm {{ $notice['type'] === 'error' ? 'border-[var(--pg-accent)] text-[var(--pg-accent)]' : 'border-[var(--pg-border)]' }}">{{ $notice['text'] }}</p>
    @endif

    <header class="flex flex-wrap items-start gap-4 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4">
        <div class="min-w-64 flex-1">
            <h1 class="font-serif text-2xl font-bold text-[var(--pg-accent)]">
                {{ $template->name }}
                @if ($template->is_official)
                    <span class="ml-1 align-middle rounded bg-[var(--pg-shade)] px-1.5 py-0.5 font-sans text-[0.6rem] uppercase text-[var(--pg-muted)]">oficial</span>
                @endif
            </h1>
            @if ($template->tagline)
                <p class="text-sm">{{ $template->tagline }}</p>
            @endif
            <p class="mt-1 text-xs text-[var(--pg-muted)]">
                {{ $template->game_line ?: 'Casero' }} · de {{ $template->owner?->displayName() }}
                · versión {{ $version->version }}{{ $version->label ? ' ('.$version->label.')' : '' }}, {{ $version->published_at?->diffForHumans() }}
                @if ($template->forkedFrom && $template->forkedFrom->visibility === 'public')
                    · basada en <a href="{{ route('catalog.show', $template->forkedFrom->slug) }}" wire:navigate class="hover:text-[var(--pg-accent)]">{{ $template->forkedFrom->name }}</a>
                @endif
            </p>
            <p class="mt-2 flex gap-4 text-sm text-[var(--pg-muted)]">
                <span title="Personas que la usan">⬇ {{ $template->installs_count }} la usan</span>
                <span>♥ {{ $template->likes_count }}</span>
                @if ($forks)<span>⑂ {{ $forks }} versión(es) de la comunidad</span>@endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="toggleLike" aria-pressed="{{ $liked ? 'true' : 'false' }}"
                    class="pg-btn-ghost text-sm {{ $liked ? 'border-[var(--pg-accent)] text-[var(--pg-accent)]' : '' }}">
                {{ $liked ? '♥ Te gusta' : '♡ Me gusta' }}
            </button>
            <a href="{{ route('templates.export', $template) }}" class="pg-btn-ghost text-sm">Exportar JSON</a>
            @if ($isOwner)
                <a href="{{ route('templates.builder', $template) }}" wire:navigate class="pg-btn-ghost text-sm">Editar</a>
            @else
                <button type="button" wire:click="fork" class="pg-btn-ghost text-sm" title="Una copia tuya, privada, para cambiarla">Clonar</button>
            @endif
            <button type="button" wire:click="useTemplate" class="pg-btn text-sm">Crear una hoja</button>
        </div>
    </header>

    @if ($template->description)
        <section class="pg-markdown rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4 text-sm">
            {!! \Illuminate\Support\Str::markdown($template->description, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
        </section>
    @endif

    @if ($version->changelog)
        <section class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-4 text-sm">
            <h2 class="pg-label">Novedades de esta versión</h2>
            <p class="whitespace-pre-line">{{ $version->changelog }}</p>
        </section>
    @endif

    {{-- Vista previa: una hoja nueva, con los colores de su tema. --}}
    <section>
        <h2 class="mb-2 font-serif text-lg font-bold text-[var(--pg-accent)]">Así es una hoja nueva</h2>
        @php $c = $theme[$theme['mode'] === 'dark' ? 'colors_dark' : 'colors']; @endphp
        <div class="space-y-3 rounded-lg p-3" style="background: {{ $c['bg'] }}; color: {{ $c['ink'] }}" data-preview>
            @foreach ($preview as $tab)
                <details class="rounded-md" style="background: {{ $c['surface'] }}; border: 1px solid {{ $c['border'] }}" {{ $loop->first ? 'open' : '' }}>
                    <summary class="cursor-pointer px-3 py-2 font-serif font-bold" style="color: {{ $c['accent'] }}">{{ $tab['label'] }}</summary>
                    <div class="space-y-3 px-3 pb-3">
                        @foreach ($tab['sections'] as $section)
                            <div>
                                @if ($section['label'])
                                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide" style="color: {{ $c['muted'] }}">{{ $section['label'] }}</h3>
                                @endif
                                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
                                    @foreach ($section['rows'] as $row)
                                        @foreach ($row as $cell)
                                            @continue($cell['kind'] === 'heading')
                                            <div class="min-w-0 {{ in_array($cell['kind'], ['table', 'list'], true) ? 'col-span-2 sm:col-span-4' : '' }}">
                                                <span class="block truncate text-[0.65rem] uppercase" style="color: {{ $c['muted'] }}">{{ $cell['label'] }}</span>
                                                <span class="block truncate">
                                                    @switch($cell['kind'])
                                                        @case('boxes') {{ implode(' ', $cell['boxes']) }} @break
                                                        @case('table') {{ implode(' · ', $cell['columns']) }} @break
                                                        @case('list') {{ collect($cell['rows'])->pluck('label')->implode(', ') }} @break
                                                        @case('image') 🖼 @break
                                                        @default {{ $cell['text'] !== '' ? $cell['text'] : '—' }}
                                                    @endswitch
                                                </span>
                                            </div>
                                        @endforeach
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
    </section>

    @unless ($isOwner)
        <details class="text-sm">
            <summary class="cursor-pointer text-[var(--pg-muted)]">Reportar esta plantilla</summary>
            <form wire:submit="report" class="mt-2 max-w-lg space-y-2 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-3">
                <select wire:model="reportReason" class="pg-input" aria-label="Motivo">
                    <option value="">Elige un motivo…</option>
                    @foreach ($reasons as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <textarea wire:model="reportDetails" rows="2" class="pg-input" placeholder="Detalles (opcional)" aria-label="Detalles"></textarea>
                <button type="submit" class="pg-btn-ghost text-sm">Enviar reporte</button>
            </form>
        </details>
    @endunless
</div>
