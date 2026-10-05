<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * FATE Básico: escalera de habilidades, estrés y consecuencias.
 *
 * La plantilla ya no se construye en código: es database/seeders/official/fate-basico.json
 * (formato de exportación del constructor). Esta clase solo la instala; ver
 * OfficialTemplateSeeder.
 */
class FateTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $official = new OfficialTemplateSeeder;

        if ($this->command) {
            $official->setCommand($this->command);
        }

        $official->install('fate-basico');
    }
}
