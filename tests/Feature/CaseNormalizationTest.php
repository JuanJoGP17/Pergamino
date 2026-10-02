<?php

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\Campaign;
use App\Models\User;
use Livewire\Livewire;

/**
 * PostgreSQL compara texto distinguiendo mayúsculas; MySQL, con su collation
 * por defecto, no. Es la única diferencia entre motores que cambia el
 * comportamiento de la aplicación (§3.1 y §15.5 del plan), y queda fijada aquí.
 */
it('guarda el correo en minúsculas', function () {
    $user = User::factory()->create(['email' => '  Juan@Gmail.COM ']);

    expect($user->fresh()->email)->toBe('juan@gmail.com');
});

it('no deja registrar el mismo correo cambiando mayúsculas', function () {
    User::factory()->create(['email' => 'juan@gmail.com']);

    Livewire::test(Register::class)
        ->set('name', 'Otro Juan')
        ->set('email', 'Juan@Gmail.com')
        ->set('password', 'una-clave-larga')
        ->set('password_confirmation', 'una-clave-larga')
        ->call('register')
        ->assertHasErrors(['email' => 'unique']);

    expect(User::count())->toBe(1);
});

it('deja entrar escribiendo el correo con otras mayúsculas', function () {
    User::factory()->create(['email' => 'juan@gmail.com', 'password' => 'una-clave-larga']);

    Livewire::test(Login::class)
        ->set('email', 'JUAN@gmail.com')
        ->set('password', 'una-clave-larga')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
});

it('encuentra una mesa aunque el código se teclee en minúsculas', function () {
    $gm = User::factory()->create();
    $campaign = Campaign::create([
        'uuid' => (string) str()->uuid(),
        'gm_id' => $gm->id,
        'name' => 'La cripta',
        'join_code' => 'aurora-7421',
    ]);

    expect($campaign->fresh()->join_code)->toBe('AURORA-7421')
        ->and(Campaign::findByJoinCode(' Aurora-7421 ')?->id)->toBe($campaign->id);
});
