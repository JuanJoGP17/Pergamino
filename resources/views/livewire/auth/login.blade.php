<div>
    <h2 class="mb-4 font-serif text-lg font-bold">Entrar</h2>

    <form wire:submit="login" class="space-y-4">
        <div>
            <label class="pg-label" for="email">Correo</label>
            <input id="email" type="email" wire:model="email" class="pg-input" autofocus autocomplete="username">
            @error('email') <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="pg-label" for="password">Contraseña</label>
            <input id="password" type="password" wire:model="password" class="pg-input" autocomplete="current-password">
            @error('password') <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-[var(--pg-muted)]">
            <input type="checkbox" wire:model="remember"> Recordarme
        </label>

        <button type="submit" class="pg-btn w-full">Entrar</button>
    </form>

    <p class="mt-4 text-center text-sm text-[var(--pg-muted)]">
        ¿Sin cuenta?
        <a href="{{ route('register') }}" wire:navigate class="text-[var(--pg-accent)] underline">Crear una</a>
    </p>
</div>
