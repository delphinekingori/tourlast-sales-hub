<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Personal profile: photo, primary details and password.
 */
#[Title('My profile')]
class Profile extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $phone = '';

    public string $job_title = '';

    public string $bio = '';

    public string $emergency_contact_name = '';

    public string $emergency_contact_phone = '';

    /** @var mixed */
    public $photo = null;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->phone = (string) $user->phone;
        $this->job_title = (string) $user->job_title;
        $this->bio = (string) $user->bio;
        $this->emergency_contact_name = (string) $user->emergency_contact_name;
        $this->emergency_contact_phone = (string) $user->emergency_contact_phone;
    }

    public function updatedPhoto(): void
    {
        $this->validate(['photo' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:3072']], [], ['photo' => 'photo']);

        $user = Auth::user();
        $old = $user->avatar_path;

        $user->update(['avatar_path' => $this->photo->store('avatars', 'public')]);

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        $this->reset('photo');
        $this->dispatch('toast', message: 'Profile photo updated.');
    }

    public function removePhoto(): void
    {
        $user = Auth::user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }
    }

    public function saveDetails(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'job_title' => ['required', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
        ], [], ['job_title' => 'position']);

        Auth::user()->update(array_map(fn ($value) => $value === '' ? null : $value, $validated));
        $this->dispatch('toast', message: 'Your profile was saved.');
    }

    public function changePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], ['current_password.current_password' => 'That isn’t your current password.']);

        Auth::user()->update(['password' => $this->password]);
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->dispatch('toast', message: 'Your password was changed.');
    }

    /**
     * Revoke one of your own API tokens (apps and devices signed in to the API).
     */
    public function revokeToken(int $tokenId): void
    {
        Auth::user()->tokens()->whereKey($tokenId)->firstOrFail()->delete();
        $this->dispatch('toast', message: 'Token revoked.');
    }

    public function render(): View
    {
        return view('livewire.profile', [
            'user' => Auth::user()->fresh(),
            'tokens' => Auth::user()->tokens()->with('issuer:id,name')->latest()->get(),
        ]);
    }
}
