<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Livewire\Auth\AcceptInvitation;
use App\Livewire\Team\Index;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sales_admin_can_invite_a_salesperson(): void
    {
        Mail::fake();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('openInvite')
            ->set('invite.name', 'John Doe')
            ->set('invite.email', 'John@Tourlast.com')
            ->set('invite.phone', '+254700000000')
            ->set('invite.role', Role::Salesperson->value)
            ->set('invite.region', 'Nairobi')
            ->call('sendInvite')
            ->assertHasNoErrors()
            ->assertSet('showInvite', false)
            ->assertDispatched('toast');

        $invitation = Invitation::sole();
        $this->assertSame('john@tourlast.com', $invitation->email);
        $this->assertSame(Role::Salesperson, $invitation->role);
        $this->assertTrue($invitation->inviter->is($admin));
        $this->assertTrue($invitation->expires_at->between(now()->addDays(6), now()->addDays(8)));

        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('john@tourlast.com'));
    }

    public function test_the_invitation_email_comes_from_sales_and_contains_a_working_link(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create(['name' => 'Grace Njeri']);
        $invitation = Invitation::factory()->for($admin, 'inviter')->withToken('plain-token')->create(['name' => 'John Doe']);

        $mail = new InvitationMail($invitation, 'plain-token');

        $mail->assertHasSubject("You've been invited to join Tourlast Sales Hub");
        $mail->assertSeeInHtml(route('invitations.accept', 'plain-token'));
        $mail->assertSeeInHtml('Grace Njeri');
        $this->assertSame('sales@tourlast.com', config('mail.from.address'));
    }

    public function test_an_invited_person_can_accept_and_gets_their_role_and_referral_code(): void
    {
        $invitation = Invitation::factory()->withToken('secret-token')->create([
            'name' => 'John Doe',
            'email' => 'john@tourlast.com',
            'role' => Role::Salesperson,
            'region' => 'Nairobi',
        ]);

        $this->get(route('invitations.accept', 'secret-token'))->assertOk()->assertSee('Welcome, John');

        Livewire::test(AcceptInvitation::class, ['token' => 'secret-token'])
            ->set('phone', '+254711111111')
            ->set('password', 'Safari2026x')
            ->set('password_confirmation', 'Safari2026x')
            ->call('accept')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $user = User::where('email', 'john@tourlast.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole(Role::Salesperson->value));
        $this->assertSame('Nairobi', $user->region);
        $this->assertSame('+254711111111', $user->phone);
        $this->assertMatchesRegularExpression('/^TL-JOHN-\d{4}$/', $user->referralCode->code);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertTrue($invitation->fresh()->acceptedUser->is($user));
    }

    public function test_an_invitation_link_works_only_once(): void
    {
        Invitation::factory()->withToken('once')->create(['accepted_at' => now()]);

        Livewire::test(AcceptInvitation::class, ['token' => 'once'])
            ->assertSee('already been used')
            ->set('password', 'Safari2026x')
            ->set('password_confirmation', 'Safari2026x')
            ->call('accept');

        $this->assertSame(1, User::count());
    }

    public function test_expired_revoked_and_unknown_links_are_refused(): void
    {
        Invitation::factory()->withToken('old')->expired()->create();
        Invitation::factory()->withToken('cancelled')->revoked()->create();

        $this->get(route('invitations.accept', 'old'))->assertSee('This invitation has expired');
        $this->get(route('invitations.accept', 'cancelled'))->assertSee('This invitation was cancelled');
        $this->get(route('invitations.accept', 'nonsense'))->assertSee("This invitation link isn't valid", false);
    }

    public function test_a_weak_password_is_rejected(): void
    {
        Invitation::factory()->withToken('t')->create();

        Livewire::test(AcceptInvitation::class, ['token' => 't'])
            ->set('phone', '+254700000000')
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('accept')
            ->assertHasErrors('password');
    }

    public function test_resending_replaces_the_old_link(): void
    {
        Mail::fake();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $invitation = Invitation::factory()->for($admin, 'inviter')->withToken('first-link')->expired()->create();

        Livewire::actingAs($admin)->test(Index::class)->call('resendInvite', $invitation->id);

        $this->assertNull(Invitation::findByToken('first-link'));
        $this->assertTrue($invitation->fresh()->isUsable());
        Mail::assertQueued(InvitationMail::class);
    }

    public function test_a_new_invitation_cancels_older_pending_ones_for_the_same_email(): void
    {
        Mail::fake();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $older = Invitation::factory()->for($admin, 'inviter')->create(['email' => 'mary@tourlast.com']);

        Livewire::actingAs($admin)->test(Index::class)
            ->set('invite', ['name' => 'Mary', 'email' => 'mary@tourlast.com', 'phone' => '', 'role' => 'salesperson', 'region' => ''])
            ->call('sendInvite');

        $this->assertNotNull($older->fresh()->revoked_at);
        $this->assertSame(1, Invitation::query()->pending()->count());
    }

    public function test_an_admin_can_cancel_a_pending_invitation(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $invitation = Invitation::factory()->for($admin, 'inviter')->withToken('abc')->create();

        Livewire::actingAs($admin)->test(Index::class)->call('revokeInvite', $invitation->id);

        $this->assertFalse($invitation->fresh()->isUsable());
        $this->get(route('invitations.accept', 'abc'))->assertSee('This invitation was cancelled');
    }

    public function test_existing_accounts_cannot_be_invited_again(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        User::factory()->create(['email' => 'taken@tourlast.com']);

        Livewire::actingAs($admin)->test(Index::class)
            ->set('invite', ['name' => 'X', 'email' => 'taken@tourlast.com', 'phone' => '', 'role' => 'salesperson', 'region' => ''])
            ->call('sendInvite')
            ->assertHasErrors('invite.email');
    }
}
