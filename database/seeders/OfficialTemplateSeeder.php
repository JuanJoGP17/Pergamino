<?php

namespace Database\Seeders;

use App\Domain\Schema\PublishTemplate;
use App\Domain\Transfer\TemplateTransfer;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Plantillas oficiales como DATOS, no como código (Fase 7: «construidas con
 * el constructor»).
 *
 * Cada una es un JSON en database/seeders/official/ con el mismo formato que
 * exporta el constructor (TemplateTransfer). Para cambiar una plantilla
 * oficial: se importa, se edita en el constructor, se exporta y se deja aquí
 * el JSON. Este seeder la importa por el mismo camino que cualquier usuario,
 * la marca como oficial y pública, y la publica.
 *
 * Una plantilla que ya existe (por su slug) no se toca: puede tener hojas.
 */
class OfficialTemplateSeeder extends Seeder
{
    /** slug => archivo */
    public const TEMPLATES = [
        'dnd-5e' => 'dnd-5e.json',
        'pathfinder-1e' => 'pathfinder-1e.json',
        'generico' => 'generico.json',
        'fate-basico' => 'fate-basico.json',
        'vampiro-v5' => 'vampiro-v5.json',
        'blades-in-the-dark' => 'blades-in-the-dark.json',
    ];

    public function run(): void
    {
        foreach (array_keys(self::TEMPLATES) as $slug) {
            $this->install($slug);
        }
    }

    public function install(string $slug): ?Template
    {
        if (Template::where('slug', $slug)->exists()) {
            $this->command?->info("La plantilla «{$slug}» ya existe; no se toca.");

            return null;
        }

        $path = database_path('seeders/official/'.(self::TEMPLATES[$slug] ?? throw new RuntimeException("No hay plantilla oficial «{$slug}».")));
        $json = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $owner = $this->owner();
        [$template, $warnings] = app(TemplateTransfer::class)->import($owner, $json);

        if ($warnings !== []) {
            throw new RuntimeException("La plantilla oficial «{$slug}» no se importa limpia: ".implode(' ', $warnings));
        }

        $template->forceFill(['slug' => $slug, 'visibility' => 'public', 'is_official' => true])->save();

        $version = app(PublishTemplate::class)($template, $owner, label: $json['template']['version_label'] ?? 'Oficial');

        $this->command?->info("Plantilla «{$template->name}» publicada (versión {$version->version}, ".count($version->compiled_schema['fields'] ?? []).' campos).');

        return $template;
    }

    /** El primer usuario, o uno «Sistema» si la base está vacía. */
    private function owner(): User
    {
        return User::query()->orderBy('id')->first()
            ?? User::create([
                'name' => 'Sistema',
                'email' => 'sistema@pergamino.local',
                'password' => bin2hex(random_bytes(16)),
            ]);
    }
}
