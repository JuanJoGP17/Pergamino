<?php

namespace App\Domain\Sheet;

use App\Models\Sheet;
use App\Models\Template;
use App\Models\User;
use RuntimeException;

/**
 * Crea una hoja anclada a la versión publicada actual de una plantilla.
 */
final class CreateSheet
{
    public function __construct(private SheetCalculator $calculator = new SheetCalculator) {}

    public function __invoke(Template $template, User $owner, ?string $name = null): Sheet
    {
        if (! $template->isPublished()) {
            throw new RuntimeException(
                "La plantilla «{$template->name}» no tiene ninguna versión publicada todavía."
            );
        }

        $version = $template->currentVersion;
        $schema = $version->schema();
        $data = $schema->defaultData();

        return Sheet::create([
            'owner_id' => $owner->id,
            'template_id' => $template->id,
            'template_version_id' => $version->id,
            'name' => $name ?: 'Personaje sin nombre',
            'data' => $data,
            'computed' => $this->calculator->calculate($schema, $data),
            'visibility' => 'private',
        ]);
    }
}
