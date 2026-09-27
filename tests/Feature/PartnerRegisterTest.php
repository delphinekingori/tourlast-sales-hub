<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
use App\Enums\Role;
use App\Livewire\Admin\Integration;
use App\Livewire\Onboardings\Unattributed;
use App\Livewire\Partners\Index;
use App\Mail\ManagerDailyAlert;
use App\Models\Onboarding;
use App\Models\SandboxProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PartnerRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_and_accounts_can_open_the_register_but_salespeople_cannot(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Hr)->create())->get(route('partners.index'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Accounts)->create())->get(route('partners.index'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('partners.index'))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('partners.export'))->assertForbidden();
    }

    public function test_the_register_filters_by_salesperson_and_status(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        Onboarding::factory()->forSalesperson($john)->active()->create(['property_name' => 'Johns Villa']);
        Onboarding::factory()->forSalesperson($mary)->active()->create(['property_name' => 'Marys Hotel']);
        Onboarding::factory()->forSalesperson($mary)->create(['property_name' => 'Marys Pending Lodge']);

        Livewire::actingAs(User::factory()->withRole(Role::Hr)->create())
            ->test(Index::class)
            ->set('from', '')->set('to', '')
            ->assertSee('Johns Villa')->assertSee('Marys Hotel')->assertDontSee('Marys Pending Lodge')
            ->set('salesperson', (string) $mary->id)
            ->assertDontSee('Johns Villa')->assertSee('Marys Hotel')
            ->set('status', 'awaiting')
            ->assertSee('Marys Pending Lodge')->assertDontSee('Marys Hotel');
    }

    public function test_excel_and_pdf_downloads_work_for_accounts(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        Onboarding::factory()->forSalesperson($john)->active()->count(3)->create();
        $accounts = User::factory()->withRole(Role::Accounts)->create();

        $excel = $this->actingAs($accounts)->get(route('partners.export', ['status' => 'onboarded']));
        $excel->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $excel->headers->get('content-disposition'));

        $pdf = $this->actingAs($accounts)->get(route('partners.report', ['status' => 'onboarded']));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
    }

    public function test_an_admin_assigns_an_unattributed_signup_with_a_reason(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        app(IssueReferralCode::class)->handle($john);
        $onboarding = Onboarding::factory()->create();

        Livewire::actingAs($admin)->test(Unattributed::class)
            ->call('openAssign', $onboarding->id)
            ->set('salespersonId', (string) $john->id)
            ->set('reason', 'short')
            ->call('assign')
            ->assertHasErrors('reason')
            ->set('reason', 'Owner confirmed John guided the signup.')
            ->call('assign')
            ->assertHasNoErrors();

        $onboarding->refresh();
        $this->assertTrue($onboarding->user->is($john));
        $this->assertSame('manual', $onboarding->attribution);

        $this->actingAs(User::factory()->withRole(Role::SalesManager)->create())->get(route('onboardings.unattributed'))->assertForbidden();
    }

    public function test_managers_get_a_daily_alert_when_something_needs_attention(): void
    {
        Mail::fake();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        User::factory()->withRole(Role::Salesperson)->create();
        User::factory()->withRole(Role::Hr)->create();

        $this->artisan('hub:send-manager-alerts')->assertSuccessful();

        Mail::assertQueued(ManagerDailyAlert::class, 1);
        Mail::assertQueued(ManagerDailyAlert::class, fn (ManagerDailyAlert $mail) => $mail->hasTo($manager->email));
    }

    public function test_the_integration_simulator_creates_a_signup_that_reaches_the_salesperson(): void
    {
        config(['tourlast.source' => 'sandbox']);
        $superAdmin = User::factory()->withRole(Role::SuperAdmin)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $code = app(IssueReferralCode::class)->handle($john)->code;

        $component = Livewire::actingAs($superAdmin)->test(Integration::class)
            ->call('openSample')
            ->set('sample.ref_code', $code)
            ->set('sample.property_name', 'Sarova Whitesands')
            ->call('createSample')
            ->assertHasNoErrors();

        $onboarding = Onboarding::sole();
        $this->assertTrue($onboarding->user->is($john));

        $component->call('advance', SandboxProvider::sole()->id, 'active');
        $this->assertNotNull($onboarding->fresh()->credited_at);

        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->get(route('admin.integration'))->assertForbidden();
    }
}
