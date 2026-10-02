<?php

namespace App\Domain\Sheet;

use App\Domain\Schema\CompiledSchema;
use App\Domain\Schema\FieldType;
use App\Models\Media;
use App\Models\Sheet;
use App\Models\SheetRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Guardar una hoja: recalcular derivados, persistir y dejar una revisión.
 *
 * Las revisiones se podan a las 30 más recientes por hoja. Sin la poda, una
 * sesión de juego larga con autoguardado cada 800 ms llenaría la tabla.
 */
final class SaveSheet
{
    public const KEEP_REVISIONS = 30;

    public function __construct(private SheetCalculator $calculator = new SheetCalculator) {}

    /** @param array<string,mixed> $data */
    public function __invoke(Sheet $sheet, array $data, ?User $editor = null, bool $revision = true): Sheet
    {
        $schema = $sheet->schema();

        // Nunca confiar en el JSON que llega del cliente: se descartan claves
        // que no existen en el esquema y las de campos derivados, y cada valor
        // sale con la forma exacta de su tipo (FieldValue).
        $clean = [];
        foreach ($schema->defaultData() as $key => $default) {
            $raw = array_key_exists($key, $data) ? $data[$key] : ($sheet->data[$key] ?? $default);
            $clean[$key] = FieldValue::normalize($schema->field($key), $raw);
        }

        $clean = $this->ownMediaOnly($sheet, $schema, $clean);

        // readonly_if se impone aquí, no solo deshabilitando el input: un
        // cliente puede mandar lo que quiera. La condición se evalúa sobre el
        // estado ANTERIOR, el que veía quien editaba; si no, bastaría con
        // cambiar en el mismo envío el campo que desbloquea y el bloqueado.
        $previous = $sheet->data ?? [];
        $previousComputed = $sheet->computed ?? [];

        foreach (array_keys($clean) as $key) {
            $field = $schema->field($key) ?? [];

            if (! empty($field['readonly_ast'])
                && $this->calculator->isReadonly($field, $previous, $previousComputed, $schema->settings)) {
                $clean[$key] = array_key_exists($key, $previous) ? $previous[$key] : $clean[$key];
            }
        }

        [$computed, $errors] = $this->calculator->calculateWithErrors($schema, $clean);

        // Un recurso con el máximo calculado (PV máx. = nivel × dado + CON) no
        // se puede recortar al sanear, porque el máximo aún no existe. Se hace
        // ahora y, si algo cambió, se recalcula: @pv.pct depende del actual.
        if ($this->clampResources($schema, $clean, $computed)) {
            [$computed, $errors] = $this->calculator->calculateWithErrors($schema, $clean);
        }

        // Los errores viajan dentro de `computed` bajo una clave reservada: así
        // la hoja sigue siendo un único documento y el editor puede mostrar el
        // motivo junto al campo que falló, sin una consulta extra.
        if ($errors !== []) {
            $computed['_errors'] = $errors;
        }

        return DB::transaction(function () use ($sheet, $clean, $computed, $editor, $revision) {
            $previous = $sheet->data;

            $sheet->forceFill(['data' => $clean, 'computed' => $computed])->save();

            if ($revision && $previous !== $clean) {
                SheetRevision::create([
                    'sheet_id' => $sheet->id,
                    'user_id' => $editor?->id,
                    'data' => $previous,
                    'computed' => $sheet->getOriginal('computed'),
                    'summary' => $this->summarize($previous ?? [], $clean),
                    'created_at' => now(),
                ]);

                $this->prune($sheet);
            }

            return $sheet;
        });
    }

    /**
     * Un campo de imagen guarda el id de un Media. Solo vale uno que haya
     * subido el dueño de la hoja: si no, cualquiera podría escribir el id de
     * una imagen ajena y la hoja la serviría con una URL firmada.
     */
    private function ownMediaOnly(Sheet $sheet, CompiledSchema $schema, array $clean): array
    {
        $keys = array_keys(array_filter(
            $schema->fields,
            fn (array $f) => in_array($f['type'], [FieldType::Image->value, FieldType::Portrait->value], true),
        ));

        $ids = array_filter(array_map(fn ($k) => $clean[$k] ?? null, $keys));

        if ($ids === []) {
            return $clean;
        }

        $owned = Media::whereIn('id', $ids)->where('user_id', $sheet->owner_id)->pluck('id')->all();

        foreach ($keys as $key) {
            if ($clean[$key] !== null && ! in_array($clean[$key], $owned, true)) {
                $clean[$key] = null;
            }
        }

        return $clean;
    }

    /** @return bool si cambió algún valor */
    private function clampResources(CompiledSchema $schema, array &$clean, array $computed): bool
    {
        $changed = false;

        foreach ($schema->fields as $key => $field) {
            if ($field['type'] !== FieldType::Resource->value || empty($field['derived']['max_ast'])
                || ! is_numeric($computed[$key]['max'] ?? null) || ! isset($clean[$key])) {
                continue;
            }

            $fixed = FieldValue::resource($clean[$key], $field['config'] ?? [], (int) $computed[$key]['max']);

            if ($fixed !== $clean[$key]) {
                $clean[$key] = $fixed;
                $changed = true;
            }
        }

        return $changed;
    }

    /** "fuerza 14 → 16" — para que el historial se lea sin abrir cada revisión. */
    private function summarize(array $before, array $after): ?string
    {
        $changes = [];

        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;

            if ($old === $value || is_array($value) || is_array($old)) {
                continue;
            }

            $changes[] = sprintf('%s %s → %s', $key, $this->scalar($old), $this->scalar($value));

            if (count($changes) === 3) {
                break;
            }
        }

        return $changes === [] ? null : mb_substr(implode(', ', $changes), 0, 255);
    }

    private function scalar(mixed $v): string
    {
        return match (true) {
            $v === null => '—',
            is_bool($v) => $v ? 'sí' : 'no',
            default => (string) $v,
        };
    }

    private function prune(Sheet $sheet): void
    {
        $keepFrom = SheetRevision::where('sheet_id', $sheet->id)
            ->orderByDesc('id')
            ->skip(self::KEEP_REVISIONS - 1)
            ->take(1)
            ->value('id');

        if ($keepFrom) {
            SheetRevision::where('sheet_id', $sheet->id)->where('id', '<', $keepFrom)->delete();
        }
    }
}
