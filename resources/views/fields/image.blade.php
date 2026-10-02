@include('fields._media', [
    'frameClass' => 'rounded '.match ($field['config']['aspect'] ?? 'free') {
        'square' => 'aspect-square',
        'portrait' => 'aspect-[3/4]',
        'landscape' => 'aspect-video',
        default => 'min-h-24',
    },
])
