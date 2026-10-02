<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Una plantilla es un sistema de juego. D&D 5e no es código: es una fila aquí
 * con su árbol de pestañas, secciones y campos colgando.
 *
 * El borrador vivo son las tablas template_tabs/sections/fields. Publicar
 * congela ese árbol en una TemplateVersion inmutable.
 */
class Template extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const VISIBILITIES = ['private', 'unlisted', 'public'];

    protected $fillable = [
        'owner_id', 'name', 'slug', 'tagline', 'description', 'game_line',
        'settings', 'cover_image_path', 'icon', 'visibility', 'is_official',
        'forked_from_id',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_official' => 'boolean',
            'installs_count' => 'integer',
            'likes_count' => 'integer',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'current_version_id');
    }

    public function forkedFrom(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'forked_from_id');
    }

    public function tabs(): HasMany
    {
        return $this->hasMany(TemplateTab::class)->orderBy('position');
    }

    /** Todos los campos de la plantilla, sin pasar por pestañas y secciones. */
    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class);
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(Sheet::class);
    }

    // ------------------------------------------------------------------ scopes

    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    public function scopeVisibleTo($query, ?User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('visibility', 'public');
            if ($user) {
                $q->orWhere('owner_id', $user->id);
            }
        });
    }

    // ----------------------------------------------------------------- helpers

    public function isPublished(): bool
    {
        return $this->current_version_id !== null;
    }

    /** Número de la siguiente versión a publicar. */
    public function nextVersionNumber(): int
    {
        return (int) $this->versions()->max('version') + 1;
    }

    public static function makeSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plantilla';
        $slug = $base;
        $n = 1;
        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
