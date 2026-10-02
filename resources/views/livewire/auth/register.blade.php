<div>
    <h2 class="mb-4 font-serif text-lg font-bold">Crear cuenta</h2>

    <form wire:submit="register" class="space-y-4">
        <div>
            <label class="pg-label" for="name">Nombre</label>
            <input id="name" type="text" wire:model="name" class="pg-input" autofocus>
            @error('name') <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="pg-label" for="email">Correo</label>
            <input id="email" type="email" wire:model="email" class="pg-input" autocomplete="username">
            @error('email') <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="pg-label" for="password">Contraseña</label>
            <input id="password" type="password" wire:model="password" class="pg-input" autocomplete="new-password">
            @error('password') <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="pg-label" for="password_confirmation">Repite la contraseña</label>
            <input id="password_confirmation" type="password" wire:model="password_confirmation"
                   class="pg-input" autocomplete="new-password">
        </div>

        <button type="submit" class="pg-btn w-full">Crear cuenta</button>
    </form>

    <p class="mt-4 text-center text-sm text-[var(--pg-muted)]">
        ¿Ya tienes cuenta?
        <a href="{{ route('login') }}" wire:navigate class="text-[var(--pg-accent)] underline">Entrar</a>
    </p>
</div>
