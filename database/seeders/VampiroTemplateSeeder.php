<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Vampiro: La Mascarada (V5): puntos, Salud y Voluntad con daño superficial y agravado.
 *
 * La plantilla ya no se construye en código: es database/seeders/official/vampiro-v5.json
 * (formato de exportación del constructor). Esta clase solo la instala; ver
 * OfficialTemplateSeeder.
 */
class VampiroTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $official = new OfficialTemplateSeeder;

        if ($this->command) {
            $official->setCommand($this->command);
        }

        $official->install('vampiro-v5');
    }
}
