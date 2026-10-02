<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Mesa de Pergamino' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex h-full items-center justify-center bg-[var(--pg-bg)] text-[var(--pg-ink)] antialiased">
    <div class="w-full max-w-sm px-4">
        <h1 class="mb-6 text-center font-serif text-2xl font-bold text-[var(--pg-accent)]">
            Mesa de Pergamino
        </h1>
        <div class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-6 shadow-sm">
            {{ $slot }}
        </div>
    </div>
    @livewireScriptConfig
</body>
</html>
