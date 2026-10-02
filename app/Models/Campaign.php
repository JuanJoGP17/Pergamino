<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory, HasUuid;

    public const ROLES = ['gm', 'player', 'spectator'];

    protected $fillable = [
        'gm_id', 'name', 'join_code', 'description', 'banner_path',
        'default_template_id', 'settings', 'theme_override', 'is_archived',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array', 'theme_override' => 'array', 'is_archived' => 'boolean'];
    }

    public function gm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CampaignMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'campaign_members')
            ->withPivot(['role', 'nickname', 'joined_at']);
    }

    public function sheets(): BelongsToMany
    {
        return $this->belongsToMany(Sheet::class)
            ->withPivot(['share_level', 'position'])
            ->orderBy('campaign_sheet.position');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CampaignNote::class);
    }

    public function rolls(): HasMany
    {
        return $this->hasMany(DiceRoll::class)->latest('id');
    }

    public function defaultTemplate(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'default_template_id');
    }

    /**
     * El código de mesa se guarda en mayúsculas y se busca normalizado: quien
     * lo teclea a mano escribirá «aurora-7421» tarde o temprano, y PostgreSQL
     * no lo encontraría (§3.1 del plan).
     */
    protected function joinCode(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : self::normalizeJoinCode($value));
    }

    public static function normalizeJoinCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public static function findByJoinCode(string $code): ?self
    {
        return static::where('join_code', self::normalizeJoinCode($code))->first();
    }

    /** Código legible por humanos: PALABRA-1234. */
    public static function makeJoinCode(): string
    {
        $words = ['AURORA', 'GRIMORIO', 'TEMPESTAD', 'CENIZA', 'UMBRAL',
            'FARO', 'CUERVO', 'DRAGON', 'RUNA', 'OCASO'];

        do {
            $code = $words[array_rand($words)].'-'.random_int(1000, 9999);
        } while (static::where('join_code', $code)->exists());

        return $code;
    }

    public function roleOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        if ($user->id === $this->gm_id) {
            return 'gm';
        }

        return $this->members()->where('user_id', $user->id)->value('role');
    }
}
