<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isLocal() && ! User::where('email', 'dj@pergamino.local')->exists()) {
            User::create([
                'name' => 'Director de juego',
                'email' => 'dj@pergamino.local',
                'password' => 'pergamino',
            ]);

            $this->command?->info('Usuario de prueba: dj@pergamino.local / pergamino');
        }

        // Plantillas oficiales: una por sistema que el catálogo de campos debe
        // poder expresar (entregable de la Fase 4).
        $this->call([
            Dnd5eTemplateSeeder::class,
            FateTemplateSeeder::class,
            VampiroTemplateSeeder::class,
            BladesTemplateSeeder::class,
        ]);
    }
}
