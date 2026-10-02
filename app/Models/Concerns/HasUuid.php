<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Asigna un uuid al crear. Las rutas usan el uuid, nunca el id autoincremental:
 * no queremos que se puedan enumerar las hojas de otras personas.
 */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
