{{-- Retrato del personaje: imagen con marco y forma (círculo, redondeado, cuadrado). --}}
@include('fields._media', [
    'frameClass' => 'mx-auto aspect-square w-full max-w-48 '
        .match ($field['config']['shape'] ?? 'rounded') {
            'circle' => 'rounded-full',
            'square' => 'rounded-none',
            default => 'rounded-xl',
        }
        .(($field['config']['frame'] ?? true) ? ' pg-portrait-frame' : ''),
])
