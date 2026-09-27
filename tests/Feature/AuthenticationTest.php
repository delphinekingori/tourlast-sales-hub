<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_sign_in_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Sign in');
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_a_user_can_sign_in(): void
    {
        $user = User::factory()->withRole(Role::Salesperson)->create();

        Livewire::test(Login::class)
            ->set('email', strtoupper($user->email))
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_user_deactivated_mid_session_is_signed_out(): void
    {
        $user = User::factory()->withRole(Role::Salesperson)->create();
        $this->actingAs($user)->get('/')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sign_in_is_rate_limited_after_five_failures(): void
    {
        $user = User::factory()->create();

        $component = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'wrong');

        foreach (range(1, 5) as $attempt) {
            $component->call('login');
        }

        $component->set('password', 'password')->call('login')->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_reset_link_is_sent_only_to_active_accounts(): void
    {
        Notification::fake();
        $active = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        Livewire::test(ForgotPassword::class)->set('email', $active->email)->call('sendResetLink')->assertSet('sent', true);
        Livewire::test(ForgotPassword::class)->set('email', $inactive->email)->call('sendResetLink')->assertSet('sent', true);

        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
