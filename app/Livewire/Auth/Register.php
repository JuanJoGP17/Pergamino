<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register()
    {
        // Antes de validar: la regla unique compara con lo guardado, que ya
        // está en minúsculas (ver User::email()).
        $this->email = User::normalizeEmail($this->email);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [], [
            'name' => 'nombre',
            'email' => 'correo',
            'password' => 'contraseña',
        ]);

        $user = User::create($data);

        event(new Registered($user));
        Auth::login($user);
        session()->regenerate();

        return $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
