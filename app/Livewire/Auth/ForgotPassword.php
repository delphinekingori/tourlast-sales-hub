<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Reset your password')]
class ForgotPassword extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    public bool $sent = false;

    public function sendResetLink(): void
    {
        $this->validate();

        Password::sendResetLink(['email' => strtolower($this->email), 'is_active' => true]);

        // Always show the same message so the form can't be used to discover which emails have accounts.
        $this->sent = true;
    }
}
