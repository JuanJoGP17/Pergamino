<?php

namespace App\Domain\Sheet;

use App\Domain\Schema\FieldType;
use App\Models\Sheet;
use App\Models\User;

/**
 * Descanso corto o largo: devuelve a su sitio los campos que la plantilla
 * marca con `reset_on`.
 *
 *   resource  → el actual vuelve al máximo y los temporales a 0
 *   counter   → vuelve a su máximo (o a su mínimo, con reset_to: min)
 *
 * Un descanso largo también hace todo lo del corto. El resultado se guarda con
 * SaveSheet, así que pasa por el mismo saneado y deja su revisión.
 */
final class TakeRest
{
    public const KINDS = ['short' => 'Descanso corto', 'long' => 'Descanso largo'];

    public function __invoke(Sheet $sheet, string $kind, ?User $editor = null): Sheet
    {
        $kinds = $kind === 'long' ? ['short', 'long'] : ['short'];
        $schema = $sheet->schema();
        $data = $sheet->data ?? [];

        foreach ($schema->fields as $key => $field) {
            $config = $field['config'] ?? [];

            if (! in_array($config['reset_on'] ?? null, $kinds, true)) {
                continue;
            }

            $data[$key] = match ($field['type']) {
                FieldType::Resource->value => [
                    'current' => (int) ($sheet->computed[$key]['max'] ?? $data[$key]['max'] ?? 0),
                    'max' => (int) ($data[$key]['max'] ?? 0),
                    'temp' => 0,
                ],
                FieldType::Counter->value => ($config['reset_to'] ?? 'max') === 'min' || ! is_numeric($config['max'] ?? null)
                    ? ($config['min'] ?? 0)
                    : $config['max'],
                default => $data[$key] ?? null,
            };
        }

        return (new SaveSheet)($sheet, $data, $editor);
    }

    /** @return array<string,string> descansos que tienen algo que hacer en esta hoja */
    public static function available(Sheet $sheet): array
    {
        $used = array_filter(array_map(
            fn (array $field) => $field['config']['reset_on'] ?? null,
            $sheet->schema()->fields,
        ));

        if ($used === []) {
            return [];
        }

        // Si algo se recupera en el corto, el largo también lo recupera.
        return in_array('short', $used, true) ? self::KINDS : ['long' => self::KINDS['long']];
    }
}
