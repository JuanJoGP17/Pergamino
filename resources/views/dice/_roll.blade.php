{{--
    Una tirada (App\Domain\Dice\RollView): etiqueta, quién y cuándo, cada
    dado con su estado y el total. Los descartados van tachados; los de
    explosión, con ✦; los relanzados enseñan lo que tuvieron antes.

    Variables: $roll, $big (total grande, para la bandeja).
--}}
@php
    $face = fn ($v, $sides) => $sides === 'F' ? ['−', '·', '+'][$v + 1] ?? $v : $v;
@endphp
<div class="rounded-md border px-2 py-1.5 text-sm {{ $roll['crit'] ? 'border-emerald-600/60' : ($roll['fumble'] ? 'border-[var(--pg-accent)]' : 'border-[var(--pg-border)]') }}"
     data-roll="{{ $roll['id'] }}">
    <div class="flex items-baseline justify-between gap-2">
        <span class="min-w-0 truncate font-semibold">
            @if ($roll['private'])<span title="Tirada secreta del DJ">🔒</span>@endif
            {{ $roll['label'] ?: $roll['expression'] }}
        </span>
        <span class="shrink-0 text-[0.65rem] text-[var(--pg-muted)]">{{ $roll['who'] ? $roll['who'].' · ' : '' }}{{ $roll['at'] }}</span>
    </div>

    <div class="mt-1 flex items-center justify-between gap-2">
        <div class="flex min-w-0 flex-wrap items-center gap-1 font-mono text-xs text-[var(--pg-muted)]">
            <span>{{ $roll['expression'] }}</span>
            @if ($roll['mode'] !== 'normal')
                <span class="rounded bg-[var(--pg-shade)] px-1">{{ $roll['mode'] === 'advantage' ? 'ventaja' : 'desventaja' }}</span>
            @endif
            <span>→</span>
            @foreach ($roll['groups'] as $group)
                <span class="inline-flex flex-wrap gap-0.5" title="{{ $group['notation'] }}">
                    @foreach ($group['dice'] as $die)
                        <span @class([
                            'rounded px-1 tabular-nums',
                            'bg-[var(--pg-shade)] text-[var(--pg-ink)]' => $die['kept'],
                            'line-through opacity-60' => ! $die['kept'],
                            'ring-1 ring-emerald-600/70' => $die['success'] === true,
                        ]) title="{{ $die['rerolled'] ? 'Relanzado: '.implode(', ', $die['rerolled']) : '' }}">{{ $face($die['value'], $group['sides']) }}@if ($die['exploded'])✦@endif</span>
                    @endforeach
                </span>
            @endforeach
        </div>

        <span class="shrink-0 text-right">
            @if ($roll['crit'])<span class="mr-1 text-[0.65rem] font-semibold uppercase text-emerald-700 dark:text-emerald-400">crítico</span>@endif
            @if ($roll['fumble'])<span class="mr-1 text-[0.65rem] font-semibold uppercase text-[var(--pg-accent)]">pifia</span>@endif
            <span class="font-serif font-bold text-[var(--pg-accent)] {{ ($big ?? false) ? 'text-3xl' : 'text-lg' }}" data-total>{{ $roll['total'] }}</span>
            @if ($roll['successes'] !== null)<span class="text-xs text-[var(--pg-muted)]"> éxito(s)</span>@endif
        </span>
    </div>
</div>
