<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate();
        $this->ensureNotRateLimited();

        if (! Auth::attempt(
            ['email' => User::normalizeEmail($this->email), 'password' => $this->password],
            $this->remember,
        )) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Esas credenciales no coinciden con ninguna cuenta.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        return $this->redirectIntended(route('dashboard'), navigate: true);
    }

    private function ensureNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Demasiados intentos. Prueba de nuevo en {$seconds} segundos.",
        ]);
    }

    private function throttleKey(): string
    {
        return 'login|'.mb_strtolower($this->email).'|'.request()->ip();
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
