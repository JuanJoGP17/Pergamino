<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Mesa de Pergamino' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-[var(--pg-bg)] text-[var(--pg-ink)] antialiased">

<header class="border-b border-[var(--pg-border)] bg-[var(--pg-surface)]">
    <div class="mx-auto flex {{ ($wide ?? false) ? 'max-w-[1600px]' : 'max-w-6xl' }} items-center gap-6 px-4 py-3">
        <a href="{{ route('dashboard') }}" wire:navigate
           class="font-serif text-lg font-bold text-[var(--pg-accent)]">
            Mesa de Pergamino
        </a>

        <nav class="flex items-center gap-4 text-sm">
            <a href="{{ route('dashboard') }}" wire:navigate class="hover:text-[var(--pg-accent)]">Mis hojas</a>
            <a href="{{ route('campaigns.index') }}" wire:navigate class="hover:text-[var(--pg-accent)]">Mesas</a>
            <a href="{{ route('templates.index') }}" wire:navigate class="hover:text-[var(--pg-accent)]">Plantillas</a>
        </nav>

        <div class="ml-auto flex items-center gap-3 text-sm">
            <span class="text-[var(--pg-muted)]">{{ auth()->user()?->displayName() }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">Salir</button>
            </form>
        </div>
    </div>
</header>

{{-- El constructor necesita todo el ancho: paleta, lienzo e inspector. --}}
<main class="mx-auto {{ ($wide ?? false) ? 'max-w-[1600px] py-4' : 'max-w-6xl py-8' }} px-4">
    {{ $slot }}
</main>

{{-- Bandeja de dados (Fase 6): en todas las páginas, para quien ha entrado. --}}
@auth
    <livewire:dice-tray />
@endauth

@livewireScriptConfig
</body>
</html>
