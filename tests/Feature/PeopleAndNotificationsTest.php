<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
use App\Actions\SyncOnboardings;
use App\Enums\Role;
use App\Livewire\Incentives\MyEarnings;
use App\Livewire\Leads\Show as LeadShow;
use App\Livewire\Notifications\Bell;
use App\Livewire\Notifications\Index as Notifications;
use App\Livewire\Profile;
use App\Models\Announcement;
use App\Models\FollowUp;
use App\Models\IncentiveAgreement;
use App\Models\Lead;
use App\Models\PaymentDetail;
use App\Models\SandboxProvider;
use App\Models\User;
use App\Notifications\SmartAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PeopleAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $john;

    protected function setUp(): void
    {
        parent::setUp();

        $this->john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        app(IssueReferralCode::class)->handle($this->john);
        $this->john->refresh();
    }

    public function test_mpesa_details_need_a_kenyan_number_and_the_registered_name_and_are_encrypted(): void
    {
        $finance = User::factory()->withRole(Role::Accounts)->create();

        Livewire::actingAs($this->john)->test(MyEarnings::class)
            ->call('openPayment', 'mpesa')
            ->assertSet('showPayment', true)
            ->set('payment.mpesa_phone', '12345')
            ->set('payment.mpesa_name', '')
            ->call('savePayment')
            ->assertHasErrors(['payment.mpesa_phone', 'payment.mpesa_name'])
            ->set('payment.mpesa_phone', '0712 345 678')
            ->set('payment.mpesa_name', 'John Doe')
            ->call('savePayment')
            ->assertHasNoErrors()
            ->assertSet('showPayment', false);

        $detail = PaymentDetail::sole();
        $this->assertSame('mpesa', $detail->method);
        $this->assertSame('254712345678', $detail->mpesa_phone);
        $this->assertSame('JOHN DOE', $detail->mpesa_name);
        $this->assertStringNotContainsString('254712345678', (string) DB::table('payment_details')->value('mpesa_phone'));
        $this->assertSame('payment_details_changed', $finance->notifications()->sole()->data['type']);
    }

    public function test_switching_to_a_bank_account_needs_the_account_number_and_name(): void
    {
        Livewire::actingAs($this->john)->test(MyEarnings::class)
            ->call('openPayment', 'bank')
            ->set('payment.bank_name', 'Equity Bank')
            ->set('payment.account_name', '')
            ->call('savePayment')
            ->assertHasErrors(['payment.account_number', 'payment.account_name'])
            ->set('payment.account_number', '0170 2912 34567')
            ->set('payment.account_name', 'John Doe')
            ->call('savePayment')
            ->assertHasNoErrors();

        $detail = PaymentDetail::sole();
        $this->assertSame('bank', $detail->method);
        $this->assertSame('0170291234567', $detail->account_number);
        $this->assertTrue($detail->isComplete());
    }

    /**
     * @return array<string, array{Role, int}>
     */
    public static function paymentTableAccess(): array
    {
        return [
            'super admin' => [Role::SuperAdmin, 200], 'sales admin' => [Role::SalesAdmin, 200], 'hr' => [Role::Hr, 200],
            'finance' => [Role::Accounts, 200], 'sales manager' => [Role::SalesManager, 403], 'salesperson' => [Role::Salesperson, 403],
        ];
    }

    #[DataProvider('paymentTableAccess')]
    public function test_only_admins_hr_and_finance_see_the_payment_details_table(Role $role, int $status): void
    {
        PaymentDetail::create(['user_id' => $this->john->id, 'method' => 'mpesa', 'mpesa_phone' => '254712345678', 'mpesa_name' => 'JOHN DOE']);

        $response = $this->actingAs(User::factory()->withRole($role)->create())->get(route('payment-details.index'));
        $response->assertStatus($status);

        if ($status === 200) {
            $response->assertSee('254712345678')->assertSee('JOHN DOE');
        }
    }

    public function test_a_salesperson_sees_only_a_masked_number_on_their_own_page(): void
    {
        IncentiveAgreement::factory()->for($this->john)->create();
        PaymentDetail::create(['user_id' => $this->john->id, 'method' => 'mpesa', 'mpesa_phone' => '254712345678', 'mpesa_name' => 'JOHN DOE']);

        $this->actingAs($this->john)->get(route('earnings.mine'))->assertSee('2547 ••• 678')->assertDontSee('254712345678');
    }

    public function test_people_upload_a_photo_and_set_their_position(): void
    {
        Storage::fake('public');
        $manager = User::factory()->withRole(Role::SalesManager)->create(['phone' => '+254700000001']);

        Livewire::actingAs($manager)->test(Profile::class)
            ->set('photo', UploadedFile::fake()->image('me.jpg', 400, 400))
            ->assertHasNoErrors()
            ->set('job_title', '')
            ->call('saveDetails')
            ->assertHasErrors('job_title')
            ->set('job_title', 'Regional Sales Manager')
            ->set('bio', 'Covers the Coast.')
            ->call('saveDetails')
            ->assertHasNoErrors();

        $manager->refresh();
        $this->assertSame('Regional Sales Manager', $manager->job_title);
        Storage::disk('public')->assertExists($manager->avatar_path);
    }

    public function test_activity_marks_people_online_and_signing_out_clears_it(): void
    {
        $this->actingAs($this->john)->get('/')->assertSee('Online');
        $this->assertTrue($this->john->fresh()->isOnline());

        $this->post(route('logout'));
        $this->assertFalse($this->john->fresh()->isOnline());
    }

    public function test_managers_hr_and_finance_see_who_is_online_but_salespeople_do_not(): void
    {
        $this->john->update(['last_seen_at' => now()]);

        foreach ([Role::SalesManager, Role::Hr, Role::Accounts, Role::SalesAdmin] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())->get(route('people.index'))->assertOk()->assertSee('John Doe')->assertSee('Online');
        }

        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('people.index'))->assertForbidden();
    }

    public function test_salespeople_read_announcements_but_cannot_write_them(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create(['name' => 'David Otieno']);

        Livewire::actingAs($this->john)->test(Notifications::class)->call('openCompose')->assertForbidden();

        Livewire::actingAs($manager)->test(Notifications::class)
            ->call('openCompose')
            ->set('compose.title', 'Coast push')
            ->set('compose.body', 'Focus on villas this month.')
            ->set('compose.audience', ['sales'])
            ->set('compose.important', true)
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame('important', Announcement::sole()->importance);

        Livewire::actingAs($this->john)->test(Bell::class)->assertSee('Coast push')->assertSee('1');
        $this->actingAs(User::factory()->withRole(Role::Accounts)->create())->get(route('notifications.index'))->assertDontSee('Coast push');

        Livewire::actingAs($this->john)->test(Notifications::class)->call('markAllRead');
        $this->assertSame(1, Announcement::sole()->reads()->count());
    }

    /**
     * @return array<string, array{Role, bool}>
     */
    public static function publishers(): array
    {
        return [
            'super admin' => [Role::SuperAdmin, true], 'sales admin' => [Role::SalesAdmin, true], 'sales manager' => [Role::SalesManager, true],
            'hr' => [Role::Hr, true], 'finance' => [Role::Accounts, true], 'salesperson' => [Role::Salesperson, false],
        ];
    }

    #[DataProvider('publishers')]
    public function test_who_can_publish_announcements(Role $role, bool $canPublish): void
    {
        $this->assertSame($canPublish, User::factory()->withRole($role)->create()->can('publish-announcements'));
    }

    public function test_smart_alerts_reach_management_and_the_salesperson_concerned(): void
    {
        config(['tourlast.source' => 'sandbox']);
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $hr = User::factory()->withRole(Role::Hr)->create();
        $provider = SandboxProvider::factory()->create(['ref_code' => $this->john->referralCode->code, 'property_name' => 'ABC Hotel']);

        app(SyncOnboardings::class)->handle();
        $provider->update(['status' => 'approved', 'approved_at' => now()]);
        app(SyncOnboardings::class)->handle();
        $provider->update(['status' => 'active', 'active_at' => now(), 'first_booking_at' => now()]);
        app(SyncOnboardings::class)->handle();
        $provider->update(['status' => 'rejected', 'rejected_at' => now()]);
        app(SyncOnboardings::class)->handle();

        $types = $manager->notifications()->pluck('data')->pluck('type')->all();
        $this->assertEqualsCanonicalizing(['property_referred', 'partner_approved', 'first_booking', 'property_inactive'], $types);
        $this->assertCount(4, $this->john->notifications);
        $this->assertCount(0, $hr->notifications);
    }

    public function test_unattributed_signups_and_lost_deals_alert_management(): void
    {
        config(['tourlast.source' => 'sandbox']);
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        SandboxProvider::factory()->create(['ref_code' => null]);
        app(SyncOnboardings::class)->handle();

        $lead = Lead::factory()->for($this->john)->create();
        Livewire::actingAs($this->john)->test(LeadShow::class, ['lead' => $lead])->call('openLost')->set('lost.objection', 'commission')->call('markLost');

        $this->assertEqualsCanonicalizing(['onboarding_submitted', 'deal_lost'], $manager->notifications()->pluck('data')->pluck('type')->all());
    }

    public function test_daily_alerts_cover_expiring_contracts_once_and_overdue_follow_ups(): void
    {
        Notification::fake();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        IncentiveAgreement::factory()->for($this->john)->create(['ends_on' => now()->addDays(20)->toDateString()]);
        FollowUp::factory()->for($this->john)->for(Lead::factory()->for($this->john))->create(['due_at' => now()->subDays(2)]);

        $this->artisan('hub:send-daily-alerts')->assertSuccessful();
        $this->artisan('hub:send-daily-alerts')->assertSuccessful();

        Notification::assertSentTo($manager, SmartAlert::class, fn (SmartAlert $alert) => $alert->type === 'contract_expiring');
        Notification::assertSentToTimes($manager, SmartAlert::class, 3);
    }
}
