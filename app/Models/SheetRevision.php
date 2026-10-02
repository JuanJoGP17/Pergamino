<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['sheet_id', 'user_id', 'data', 'computed', 'summary'];

    protected function casts(): array
    {
        return ['data' => 'array', 'computed' => 'array', 'created_at' => 'datetime'];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
