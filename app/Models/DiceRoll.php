<?php

namespace App\Models;

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

    public function total(): int
    {
        return (int) ($this->result['total'] ?? 0);
    }
}
