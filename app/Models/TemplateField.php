<?php

namespace App\Models;

use App\Domain\Schema\FieldType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateField extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_section_id', 'template_id', 'key', 'label', 'help_text', 'type',
        'position', 'col_span', 'config', 'default_value', 'formula',
        'roll_expression', 'visible_if', 'readonly_if', 'is_required', 'is_summary',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'default_value' => 'array',
            'is_required' => 'boolean',
            'is_summary' => 'boolean',
            'position' => 'integer',
            'col_span' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TemplateSection::class, 'template_section_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function definition(): ?FieldType
    {
        return FieldType::tryFrom($this->type);
    }
}
