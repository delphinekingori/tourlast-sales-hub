<?php

namespace App\Livewire\Auth;

use App\Actions\AcceptInvitation as AcceptInvitationAction;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Accept your invitation')]
class AcceptInvitation extends Component
{
    #[Locked]
    public string $token = '';

    public string $phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->phone = (string) $this->invitation()?->phone;
    }

    public function accept(AcceptInvitationAction $acceptInvitation): void
    {
        $invitation = $this->invitation();

        if (! $invitation?->isUsable()) {
            return;
        }

        $this->validate([
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (User::where('email', $invitation->email)->exists()) {
            $this->addError('password', 'An account with this email already exists. Sign in instead.');

            return;
        }

        $user = $acceptInvitation->handle($invitation, $this->password, $this->phone);

        Auth::login($user);
        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        session()->flash('toast', ['message' => "Welcome to Tourlast Sales Hub, {$user->firstName()}."]);
        $this->redirectRoute('dashboard', navigate: true);
    }

    protected function invitation(): ?Invitation
    {
        return Invitation::findByToken($this->token);
    }

    public function render(): View
    {
        $invitation = $this->invitation();

        return view('livewire.auth.accept-invitation', [
            'invitation' => $invitation,
            'status' => $invitation?->status(),
            'statuses' => InvitationStatus::class,
        ]);
    }
}
