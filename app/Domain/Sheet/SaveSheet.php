<?php

namespace App\Domain\Sheet;

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
        // que no existen en el esquema y las de campos derivados.
        $clean = [];
        foreach ($schema->defaultData() as $key => $default) {
            $clean[$key] = array_key_exists($key, $data) ? $data[$key] : ($sheet->data[$key] ?? $default);
        }

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
