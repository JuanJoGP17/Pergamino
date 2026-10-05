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

    /**
     * /hojas/abc es un 404, no un error: en PostgreSQL la columna es de tipo
     * uuid y comparar con un texto que no lo sea lo rechaza la base de datos.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (($field ?? $this->getRouteKeyName()) === 'uuid' && ! Str::isUuid((string) $value)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
