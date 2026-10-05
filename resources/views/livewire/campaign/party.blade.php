{{-- Tarjetas resumen del grupo. Se repinta sola (polling) para seguir la partida. --}}
<div wire:poll.6s class="space-y-2">
    @if ($error)
        <p class="text-xs text-[var(--pg-accent)]" role="alert">{{ $error }}</p>
    @endif

    @if ($cards->isEmpty())
        <p class="rounded-lg border border-dashed border-[var(--pg-border)] p-6 text-center text-sm text-[var(--pg-muted)]">
            Todavía no hay hojas en la mesa.
        </p>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($cards as $card)
                @php $sheet = $card['sheet']; $level = $sheet->pivot->share_level; @endphp
                <article wire:key="card-{{ $sheet->id }}" data-card="{{ $sheet->uuid }}"
                         class="rounded-lg border border-[var(--pg-border)] bg-[var(--pg-surface)] p-3 {{ $level === 'hidden' ? 'opacity-70' : '' }}">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            @if ($card['canOpen'])
                                <a href="{{ route('sheets.edit', $sheet) }}" wire:navigate class="font-serif font-bold text-[var(--pg-accent)] hover:underline">{{ $sheet->name }}</a>
                            @else
                                <span class="font-serif font-bold text-[var(--pg-accent)]">{{ $sheet->name }}</span>
                            @endif
                            <p class="truncate text-[0.7rem] text-[var(--pg-muted)]">{{ $sheet->owner?->displayName() }} · {{ $sheet->template?->name }}</p>
                        </div>
                        <span class="shrink-0 rounded bg-[var(--pg-shade)] px-1.5 py-0.5 text-[0.6rem] uppercase text-[var(--pg-muted)]">
                            {{ ['full' => 'completa', 'summary' => 'resumen', 'hidden' => 'oculta'][$level] ?? $level }}
                        </span>
                    </div>

                    @if ($card['summary'])
                        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                            @foreach ($card['summary'] as $item)
                                <div class="min-w-0">
                                    <dt class="truncate text-[0.65rem] uppercase text-[var(--pg-muted)]">{{ $item['label'] }}</dt>
                                    <dd class="truncate font-semibold">
                                        @if ($item['kind'] === 'boxes')
                                            <span class="tracking-widest">{{ implode('', $item['boxes']) }}</span>
                                        @else
                                            {{ $item['text'] !== '' ? $item['text'] : '—' }}
                                            @if ($item['sub'] ?? null)<span class="text-xs font-normal text-[var(--pg-accent)]">{{ $item['sub'] }}</span>@endif
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @else
                        <p class="mt-2 text-xs text-[var(--pg-muted)]">La plantilla no marca campos para el resumen.</p>
                    @endif

                    @if ($card['canManage'])
                        <div class="mt-2 flex items-center gap-2 border-t border-[var(--pg-border)] pt-2 text-xs">
                            <select class="pg-input w-auto py-0.5 text-xs" aria-label="Cuánto se ve de {{ $sheet->name }}"
                                    wire:change="setShareLevel('{{ $sheet->uuid }}', $event.target.value)">
                                <option value="full" @selected($level === 'full')>Completa</option>
                                <option value="summary" @selected($level === 'summary')>Solo resumen</option>
                                <option value="hidden" @selected($level === 'hidden')>Oculta</option>
                            </select>
                            <button type="button" class="ml-auto text-[var(--pg-muted)] hover:text-[var(--pg-accent)]"
                                    wire:click="removeSheet('{{ $sheet->uuid }}')" wire:confirm="¿Sacar «{{ $sheet->name }}» de la mesa? La hoja no se borra.">Sacar</button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
