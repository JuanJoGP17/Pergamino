<?php

namespace App\Models;

use App\Domain\Schema\CompiledSchema;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una hoja de personaje: valores + puntero a la versión de plantilla que la
 * describe. Nunca apunta a la plantilla "viva", siempre a una versión
 * congelada, para que editar la plantilla no rompa partidas en curso.
 */
class Sheet extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const VISIBILITIES = ['private', 'campaign', 'unlisted', 'public'];

    protected $fillable = [
        'owner_id', 'template_id', 'template_version_id', 'name',
        'portrait_path', 'banner_path', 'data', 'computed',
        'theme_override', 'visibility',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'computed' => 'array',
            'theme_override' => 'array',
            'is_template_dirty' => 'boolean',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SheetRevision::class)->latest('created_at');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(SheetShare::class);
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class)
            ->withPivot(['share_level', 'position']);
    }

    public function rolls(): HasMany
    {
        return $this->hasMany(DiceRoll::class);
    }

    // ----------------------------------------------------------------- helpers

    /** El esquema compilado de la versión a la que está anclada esta hoja. */
    public function schema(): CompiledSchema
    {
        return $this->version->schema();
    }

    /**
     * Valor de un campo por su clave. Los calculados viven en `computed`, los
     * escritos por el usuario en `data`; quien lee no debería tener que saberlo.
     *
     * El orden es el mismo que usa el motor de fórmulas al resolver `@clave`:
     * primero lo que escribió el usuario, y solo si no existe, lo calculado. Un
     * campo `attribute` guarda 16 en `data` y {mod: 3} en `computed`; sin este
     * orden, value('fuerza') devolvería el array en vez de la puntuación.
     */
    public function value(string $key, mixed $default = null): mixed
    {
        if (is_array($this->data) && array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }

        return $this->computed[$key] ?? $default;
    }

    /** Propiedad derivada: value('fuerza', 'mod') → 3. */
    public function derived(string $key, string $property, mixed $default = null): mixed
    {
        return data_get($this->computed, "{$key}.{$property}", $default);
    }

    /**
     * Fórmulas que fallaron en el último cálculo, por clave de campo.
     *
     * @return array<string,string>
     */
    public function formulaErrors(): array
    {
        return $this->computed['_errors'] ?? [];
    }

    /** ¿Existe una versión de la plantilla más nueva que la que usa la hoja? */
    public function refreshDirtyFlag(): void
    {
        $current = $this->template->current_version_id;
        $dirty = $current !== null && $current !== $this->template_version_id;

        if ($dirty !== (bool) $this->is_template_dirty) {
            $this->forceFill(['is_template_dirty' => $dirty])->saveQuietly();
        }
    }

    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user) {
            return $query->whereIn('visibility', ['public', 'unlisted']);
        }

        return $query->where(function ($q) use ($user) {
            $q->where('owner_id', $user->id)
                ->orWhereIn('visibility', ['public', 'unlisted'])
                ->orWhereHas('shares', fn ($s) => $s->where('user_id', $user->id));
        });
    }
}
