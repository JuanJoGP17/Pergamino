<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Blades in the Dark: acciones, estrés y trauma, relojes.
 *
 * La plantilla ya no se construye en código: es database/seeders/official/blades-in-the-dark.json
 * (formato de exportación del constructor). Esta clase solo la instala; ver
 * OfficialTemplateSeeder.
 */
class BladesTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $official = new OfficialTemplateSeeder;

        if ($this->command) {
            $official->setCommand($this->command);
        }

        $official->install('blades-in-the-dark');
    }
}
