<div class="border-t border-[var(--pg-border)] pt-3">
    <button type="button" wire:click="deleteSelected"
            wire:confirm="¿Borrar {{ $what }}? Las hojas ya creadas no se ven afectadas hasta que publiques."
            class="text-xs text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">
        Borrar {{ $what }}
    </button>
</div>
