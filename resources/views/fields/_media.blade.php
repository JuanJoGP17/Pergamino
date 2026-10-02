{{--
    Imagen de una hoja (la usan `image` y `portrait`). El valor es el id de un
    Media; la imagen se sirve por una URL firmada (App\Domain\Media\MediaUrl).

    Subir solo es posible en el editor de hojas ($uploadsEnabled): la vista
    previa del constructor no guarda nada.

    Variables: $frameClass (forma y proporción del marco).
--}}
@php
    $mediaId = data_get($sheet->data, $key);
    $url = \App\Domain\Media\MediaUrl::for(is_numeric($mediaId) ? (int) $mediaId : null);
@endphp
<div>
    <span class="pg-label">{{ $field['label'] }}</span>

    <div class="relative overflow-hidden border border-[var(--pg-border)] bg-[var(--pg-shade)] {{ $frameClass }}">
        @if ($url)
            <img src="{{ $url }}" alt="{{ $field['label'] }}" class="size-full object-cover" loading="lazy">
        @else
            <div class="flex size-full min-h-24 items-center justify-center text-3xl text-[var(--pg-muted)]" aria-hidden="true">🖼</div>
        @endif

        @if ($uploadsEnabled ?? false)
            <div wire:loading.flex wire:target="uploads.{{ $key }}"
                 class="absolute inset-0 items-center justify-center bg-black/50 text-xs text-white">Subiendo…</div>
        @endif
    </div>

    @if ($uploadsEnabled ?? false)
        <div class="mt-1 flex items-center justify-between gap-2 text-xs">
            <label class="cursor-pointer text-[var(--pg-muted)] hover:text-[var(--pg-accent)]">
                {{ $url ? 'Cambiar' : 'Subir imagen' }}
                <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="sr-only"
                       wire:model="uploads.{{ $key }}"
                       @include('fields._bind', ['bindKey' => false])>
            </label>
            @if ($url)
                <button type="button" class="text-[var(--pg-muted)] hover:text-[var(--pg-accent)]"
                        wire:click="clearImage('{{ $key }}')"
                        wire:confirm="¿Quitar la imagen?"
                        @include('fields._bind', ['bindKey' => false])>Quitar</button>
            @endif
        </div>
        @error('uploads.'.$key)
            <p class="mt-1 text-xs text-[var(--pg-accent)]">{{ $message }}</p>
        @enderror
    @else
        <p class="mt-1 text-[0.65rem] text-[var(--pg-muted)]">Las imágenes se suben desde la hoja.</p>
    @endif

    @include('fields._help')
</div>
