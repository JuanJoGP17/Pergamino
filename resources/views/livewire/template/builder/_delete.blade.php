<div class="border-t border-[var(--pg-border)] pt-3">
    <button type="button" wire:click="deleteSelected"
            wire:confirm="¿Borrar {{ $what }}? Las hojas ya creadas no se ven afectadas hasta que publiques."
            class="pg-btn-danger w-full">
        Borrar {{ $what }}
    </button>
</div>
