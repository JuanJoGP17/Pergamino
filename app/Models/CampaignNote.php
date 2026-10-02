<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignNote extends Model
{
    protected $fillable = ['campaign_id', 'author_id', 'title', 'body', 'is_gm_only'];

    protected function casts(): array
    {
        return ['is_gm_only' => 'boolean'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
