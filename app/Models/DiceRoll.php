<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiceRoll extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'campaign_id', 'sheet_id', 'user_id', 'label',
        'expression', 'result', 'mode', 'is_private',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'is_private' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Las tiradas que puede ver alguien en el registro de una mesa: todas las
     * públicas, y las secretas solo quien las hizo y el DJ.
     */
    public function scopeVisibleTo(Builder $query, User $user, Campaign $campaign): Builder
    {
        $query->where('campaign_id', $campaign->id);

        if ($campaign->gm_id === $user->id) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('is_private', false)->orWhere('user_id', $user->id));
    }

    public function total(): int
    {
        return (int) ($this->result['total'] ?? 0);
    }
}
