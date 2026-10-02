<?php

namespace App\Domain\Media;

use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\ImageManager;

/**
 * Guarda una imagen subida por un usuario (§10 del plan).
 *
 *   - El tipo se comprueba por el CONTENIDO del archivo, no por la extensión.
 *   - La imagen se decodifica y se vuelve a codificar desde cero con
 *     Intervention: lo que se guarda son píxeles nuevos, sin EXIF (ni la
 *     ubicación GPS de la foto) y sin nada escondido detrás de los datos.
 *   - Se reduce a 1600 px como mucho y se guarda en WebP.
 *   - Nombre aleatorio y disco privado (storage/app/private): no hay URL
 *     pública. Se sirve con una ruta firmada, ver MediaUrl.
 */
final class StoreImage
{
    public const MAX_KB = 4096;

    public const MAX_SIDE = 1600;

    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __invoke(UploadedFile $file, User $user, string $purpose = 'portrait'): Media
    {
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw new ImageRejected('La imagen pesa más de '.(self::MAX_KB / 1024).' MB.');
        }

        // Tipo real, leído de los primeros bytes (fileinfo), no del nombre.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! in_array($mime, self::MIMES, true)) {
            throw new ImageRejected('Solo se admiten imágenes JPEG, PNG, WebP o GIF.');
        }

        try {
            $image = ImageManager::gd()->read($file->getRealPath());
        } catch (DecoderException) {
            throw new ImageRejected('El archivo no es una imagen válida.');
        }

        $image->orient()->scaleDown(self::MAX_SIDE, self::MAX_SIDE);
        $encoded = $image->toWebp(quality: 85, strip: true);

        $path = 'media/'.$user->id.'/'.Str::random(40).'.webp';
        Storage::disk('local')->put($path, (string) $encoded);

        return Media::create([
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $path,
            'mime' => 'image/webp',
            'size' => strlen((string) $encoded),
            'width' => $image->width(),
            'height' => $image->height(),
            'purpose' => $purpose,
        ]);
    }
}
