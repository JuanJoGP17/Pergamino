{{-- Barra superior: nombre, estado del borrador, Diseño / Vista previa y Publicar. --}}
<div class="flex flex-wrap items-center gap-3 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] px-4 py-3">
    <div class="min-w-48 flex-1">
        <a href="{{ route('templates.index') }}" wire:navigate class="text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">← Plantillas</a>
        <h1 class="font-serif text-xl font-bold text-[var(--pg-accent)]">
            <button type="button" wire:click="select('template')" class="hover:underline">{{ $template->name }}</button>
        </h1>
        <p class="text-xs text-[var(--pg-muted)]" data-test="estado">
            @if (! $template->currentVersion)
                Borrador, todavía sin publicar
            @elseif ($unpublished)
                Versión {{ $template->currentVersion->version }} publicada · <span class="font-semibold text-[var(--pg-accent)]">hay cambios sin publicar</span>
            @else
                Versión {{ $template->currentVersion->version }} publicada · al día
            @endif
        </p>
    </div>

    <div class="flex overflow-hidden rounded-md border border-[var(--pg-border)] text-sm" role="tablist">
        <button type="button" class="px-3 py-1.5" role="tab"
                x-on:click="view = 'canvas'" x-bind:class="view === 'canvas' && 'bg-[var(--pg-accent)] text-white'">Diseño</button>
        <button type="button" class="px-3 py-1.5" role="tab"
                x-on:click="view = 'preview'" x-bind:class="view === 'preview' && 'bg-[var(--pg-accent)] text-white'">Vista previa</button>
    </div>

    <div class="flex overflow-hidden rounded-md border border-[var(--pg-border)] text-xs" x-show="view === 'preview'" x-cloak>
        <button type="button" class="px-2 py-1.5" x-on:click="device = 'desktop'" x-bind:class="device === 'desktop' && 'bg-[var(--pg-shade)] font-semibold'">Escritorio</button>
        <button type="button" class="px-2 py-1.5" x-on:click="device = 'mobile'" x-bind:class="device === 'mobile' && 'bg-[var(--pg-shade)] font-semibold'">Móvil</button>
    </div>

    <div class="relative">
        <button type="button" class="pg-btn disabled:opacity-50" x-on:click="publishing = ! publishing"
                @disabled($errorCount > 0)
                title="{{ $errorCount > 0 ? 'Corrige los errores del panel de validación para poder publicar' : 'Congelar este borrador como una versión nueva' }}">
            Publicar…
        </button>

        <form wire:submit="publish" x-show="publishing" x-cloak x-on:click.outside="publishing = false"
              class="absolute right-0 z-20 mt-2 w-80 space-y-2 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-3 shadow-lg">
            <p class="text-xs text-[var(--pg-muted)]">
                Publicar crea la versión {{ $template->nextVersionNumber() }}. Las hojas ya creadas siguen en su versión hasta que su dueño decida actualizar.
            </p>
            <div>
                <label class="pg-label" for="publish-label">Nombre de la versión (opcional)</label>
                <input id="publish-label" type="text" class="pg-input" wire:model="publishLabel" placeholder="1.1 – añadidas las salvaciones">
            </div>
            <div>
                <label class="pg-label" for="publish-changelog">Cambios (opcional)</label>
                <textarea id="publish-changelog" rows="3" class="pg-input" wire:model="publishChangelog"></textarea>
            </div>
            <button type="submit" class="pg-btn w-full" x-on:click="publishing = false">Publicar versión {{ $template->nextVersionNumber() }}</button>
        </form>
    </div>
</div>
