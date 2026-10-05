{{--
    Compartir la hoja (Fase 7): con una persona por su correo, o con un enlace
    de lectura (se ve sin entrar) o de edición (pide entrar). Solo el dueño.
    Ver App\Domain\Sheet\SheetSharing.
--}}
<div class="relative" x-data="{ open: false }">
    <button type="button" class="pg-btn-ghost py-1 text-xs" x-on:click="open = ! open">
        Compartir @if ($shares->isNotEmpty())<span class="text-[var(--pg-muted)]">({{ $shares->count() }})</span>@endif
    </button>

    <div x-show="open" x-cloak x-on:click.outside="open = false"
         class="absolute right-0 z-20 mt-2 w-96 max-w-[calc(100vw-2rem)] space-y-3 rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-3 text-sm shadow-lg">

        <form wire:submit="shareWithUser" class="space-y-1">
            <p class="pg-label">Con una persona</p>
            <div class="flex gap-1">
                <input type="email" wire:model="shareEmail" class="pg-input py-1 text-xs" placeholder="correo@ejemplo.com" aria-label="Correo">
                <select wire:model="shareAbility" class="pg-input w-28 py-1 text-xs" aria-label="Permiso">
                    <option value="view">Puede ver</option>
                    <option value="edit">Puede editar</option>
                </select>
                <button type="submit" class="pg-btn py-1 text-xs">Dar acceso</button>
            </div>
        </form>

        <form wire:submit="createShareLink" class="space-y-1">
            <p class="pg-label">Con un enlace</p>
            <div class="flex gap-1">
                <select wire:model="linkAbility" class="pg-input py-1 text-xs" aria-label="Permiso del enlace">
                    <option value="view">Lectura (sin entrar)</option>
                    <option value="edit">Edición (pide entrar)</option>
                </select>
                <select wire:model="linkDays" class="pg-input w-28 py-1 text-xs" aria-label="Caducidad">
                    <option value="">No caduca</option>
                    <option value="1">1 día</option>
                    <option value="7">7 días</option>
                    <option value="30">30 días</option>
                </select>
                <button type="submit" class="pg-btn py-1 text-xs">Crear</button>
            </div>
        </form>

        @if ($shares->isNotEmpty())
            <ul class="space-y-1 border-t border-[var(--pg-border)] pt-2" data-shares>
                @foreach ($shares as $share)
                    <li wire:key="share-{{ $share->id }}" class="flex items-center gap-2 text-xs" x-data="{ copied: false }">
                        <span class="min-w-0 flex-1 truncate">
                            @if ($share->token)
                                🔗 Enlace de {{ $share->ability === 'edit' ? 'edición' : 'lectura' }}
                                <button type="button" class="text-[var(--pg-accent)] hover:underline" data-share-link="{{ route('shared.show', $share->token) }}"
                                        x-on:click="navigator.clipboard?.writeText(@js(route('shared.show', $share->token))); copied = true; setTimeout(() => copied = false, 1500)">
                                    <span x-show="! copied">copiar</span><span x-show="copied" x-cloak>¡copiado!</span>
                                </button>
                            @else
                                👤 {{ $share->user?->displayName() }} · {{ $share->ability === 'edit' ? 'puede editar' : 'puede ver' }}
                            @endif
                            @if ($share->expires_at)
                                <span class="{{ $share->isExpired() ? 'text-[var(--pg-accent)]' : 'text-[var(--pg-muted)]' }}">
                                    · {{ $share->isExpired() ? 'caducó' : 'caduca' }} {{ $share->expires_at->diffForHumans() }}
                                </span>
                            @endif
                        </span>
                        <button type="button" wire:click="revokeShare({{ $share->id }})" class="text-[var(--pg-muted)] hover:text-[var(--pg-accent)]"
                                title="Quitar el acceso" aria-label="Quitar el acceso">&times;</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
