<?php

namespace Database\Factories;

use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'owner_id' => User::factory(),
            'name' => ucfirst($name),
            'slug' => str($name)->slug()->append('-'.fake()->unique()->numberBetween(1, 99999)),
            'game_line' => 'Casero',
            'visibility' => 'private',
        ];
    }
}
