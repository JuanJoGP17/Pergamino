<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateTab extends Model
{
    use HasFactory;

    protected $fillable = ['template_id', 'key', 'label', 'icon', 'position', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'position' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(TemplateSection::class)->orderBy('position');
    }
}
