{{--
    Panel de validación (§6.2, punto 6): SchemaValidator sobre el borrador, en
    cada cambio. Los errores impiden publicar; los avisos no. Clic en uno con
    campo asociado → se selecciona ese campo.
--}}
@php
    $errorsList = array_values(array_filter($issues, fn ($i) => $i['level'] === 'error'));
    $warnings = array_values(array_filter($issues, fn ($i) => $i['level'] !== 'error'));
@endphp

<section class="rounded-lg border bg-[var(--pg-surface)] px-4 py-3
                {{ $errorsList ? 'border-[var(--pg-accent)]' : 'border-[var(--pg-border)]' }}"
         aria-label="Validación" data-test="validacion">
    @if ($issues === [])
        <p class="text-sm text-[var(--pg-muted)]">✓ Sin problemas: la plantilla se puede publicar.</p>
    @else
        <p class="mb-2 text-sm font-semibold {{ $errorsList ? 'text-[var(--pg-accent)]' : '' }}">
            {{ count($errorsList) }} error(es) · {{ count($warnings) }} aviso(s)
            @if ($errorsList) <span class="font-normal text-[var(--pg-muted)]">— corrige los errores para poder publicar</span> @endif
        </p>
        <ul class="space-y-1 text-sm">
            @foreach ([...$errorsList, ...$warnings] as $issue)
                <li class="flex gap-2">
                    <span class="{{ $issue['level'] === 'error' ? 'text-[var(--pg-accent)]' : 'text-[var(--pg-muted)]' }}">
                        {{ $issue['level'] === 'error' ? '✕' : '⚠' }}
                    </span>
                    @if ($issue['field'])
                        <button type="button" wire:click="selectFieldByKey('{{ $issue['field'] }}')" class="text-left hover:underline">{{ $issue['message'] }}</button>
                    @else
                        <span>{{ $issue['message'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
