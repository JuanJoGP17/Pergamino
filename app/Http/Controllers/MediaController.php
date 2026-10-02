<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve una imagen del disco privado. La ruta exige firma válida
 * (middleware `signed`): ver App\Domain\Media\MediaUrl.
 */
class MediaController extends Controller
{
    public function __invoke(Media $media): StreamedResponse
    {
        $disk = Storage::disk($media->disk);

        abort_unless($disk->exists($media->path), 404);

        return $disk->response($media->path, null, [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
