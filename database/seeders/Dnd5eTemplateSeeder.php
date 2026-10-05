<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * D&D 5e: la prueba de fuego del diseño (§1 del plan).
 *
 * La plantilla ya no se construye en código: es database/seeders/official/dnd-5e.json
 * (formato de exportación del constructor). Esta clase solo la instala; ver
 * OfficialTemplateSeeder.
 */
class Dnd5eTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $official = new OfficialTemplateSeeder;

        if ($this->command) {
            $official->setCommand($this->command);
        }

        $official->install('dnd-5e');
    }
}
