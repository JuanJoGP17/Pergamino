<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'display_name', 'email', 'password',
        'avatar_path', 'timezone', 'locale', 'settings',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
        ];
    }

    /**
     * El correo se guarda siempre en minúsculas.
     *
     * PostgreSQL compara texto distinguiendo mayúsculas (MySQL, con su
     * collation por defecto, no): sin esto, «Juan@Gmail.com» y
     * «juan@gmail.com» serían dos cuentas distintas (§3.1 del plan).
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : self::normalizeEmail($value));
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    // ---------------------------------------------------------------- relations

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class, 'owner_id');
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(Sheet::class, 'owner_id');
    }

    /** Mesas que dirige. */
    public function gmCampaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'gm_id');
    }

    /** Mesas en las que participa (incluye las que dirige si se auto-inscribió). */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_members')
            ->withPivot(['role', 'nickname', 'joined_at']);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    // ----------------------------------------------------------------- helpers

    public function displayName(): string
    {
        return $this->display_name ?: $this->name;
    }
}
