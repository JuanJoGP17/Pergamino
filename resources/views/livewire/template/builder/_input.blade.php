{{--
    Un input del inspector, enlazado a form.<name> y guardado al salir de él.

      name      propiedad del formulario («label», «config.min»…)
      label     texto de la etiqueta
      inputType text | number                      (por defecto text)
      textarea  true para varias líneas (textRows: cuántas; 3 por defecto)
      mono      true para fórmulas y claves
      placeholder, help
      problems  avisos del analizador de fórmulas para este input
--}}
@php
    $id = 'i-'.str_replace('.', '-', $name);
    $problems ??= [];
    $classes = 'pg-input'.(($mono ?? false) ? ' font-mono text-xs' : '')
        .($problems ? ' border-[var(--pg-accent)]' : '');
@endphp
<div>
    <label class="pg-label" for="{{ $id }}">{{ $label }}</label>
    @if ($textarea ?? false)
        <textarea id="{{ $id }}" rows="{{ $textRows ?? 3 }}" class="{{ $classes }}" wire:model.live.blur="form.{{ $name }}"
                  placeholder="{{ $placeholder ?? '' }}" spellcheck="false"></textarea>
    @else
        <input id="{{ $id }}" type="{{ $inputType ?? 'text' }}" class="{{ $classes }}" wire:model.live.blur="form.{{ $name }}"
               placeholder="{{ $placeholder ?? '' }}" spellcheck="false" autocomplete="off">
    @endif

    @error('form.'.explode('.', $name)[0])
        <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p>
    @enderror

    @foreach ($problems as $problem)
        <p class="mt-1 text-xs text-[var(--pg-accent)]">⚠ {{ $problem }}</p>
    @endforeach

    @if ($help ?? null)
        <p class="mt-1 text-[0.7rem] text-[var(--pg-muted)]">{{ $help }}</p>
    @endif
</div>
