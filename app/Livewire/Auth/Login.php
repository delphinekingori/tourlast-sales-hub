<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Sign in')]
class Login extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        $credentials = ['email' => Str::lower($this->email), 'password' => $this->password];

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'That email and password don’t match an account.',
            ]);
        }

        $user = User::where('email', $credentials['email'])->first();

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => $user->inactiveMessage(),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Auth::login($user, $this->remember);
        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->redirectIntended(route('dashboard', absolute: false), navigate: true);
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        throw ValidationException::withMessages([
            'email' => 'Too many sign-in attempts. Try again in '.RateLimiter::availableIn($this->throttleKey()).' seconds.',
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
