<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_tab_id', 'key', 'label', 'description', 'position',
        'columns', 'collapsible', 'collapsed_default', 'style', 'visible_if',
    ];

    protected function casts(): array
    {
        return [
            'style' => 'array',
            'collapsible' => 'boolean',
            'collapsed_default' => 'boolean',
            'position' => 'integer',
            'columns' => 'integer',
        ];
    }

    public function tab(): BelongsTo
    {
        return $this->belongsTo(TemplateTab::class, 'template_tab_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class)->orderBy('position');
    }
}
