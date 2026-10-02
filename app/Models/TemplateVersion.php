<?php

namespace App\Models;

use App\Domain\Schema\CompiledSchema;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * Snapshot inmutable del árbol de una plantilla. Una vez publicada no se toca:
 * hay hojas apuntando a ella.
 */
class TemplateVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id', 'version', 'label', 'changelog',
        'compiled_schema', 'published_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'compiled_schema' => 'array',
            'published_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(Sheet::class, 'template_version_id');
    }

    private ?CompiledSchema $schema = null;

    /**
     * El esquema como objeto. Se cachea sin caducidad porque la fila es
     * inmutable (§11).
     *
     * La clave no es solo el id: tras un migrate:fresh los ids se reutilizan y
     * una caché que sobreviva (archivos, Redis) devolvería el esquema de otra
     * plantilla. La fecha de publicación distingue una fila de otra con el
     * mismo id, y el formato hace que un cambio de CompiledSchema::VERSION no
     * sirva arrays con la forma vieja.
     */
    public function schema(): CompiledSchema
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $array = Cache::rememberForever(
            'tpl_v:'.CompiledSchema::VERSION.":{$this->id}:".$this->published_at?->getTimestamp(),
            fn () => $this->compiled_schema,
        );

        return $this->schema = CompiledSchema::fromArray($array);
    }
}
