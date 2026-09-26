<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
use App\Actions\SyncOnboardings;
use App\Enums\Role;
use App\Incentives\AccountPoints;
use App\Incentives\ChecklistItem;
use App\Livewire\Accounts\Show;
use App\Models\IncentiveAgreement;
use App\Models\PartnerAccount;
use App\Models\PointEntry;
use App\Models\SandboxProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AccountPointsTest extends TestCase
{
    use RefreshDatabase;

    private User $mary;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tourlast.source' => 'sandbox']);
        $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00'));
        $this->mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        app(IssueReferralCode::class)->handle($this->mary);
        $this->mary->refresh();
        IncentiveAgreement::factory()->for($this->mary)->create(['starts_on' => '2026-01-01']);
        $this->admin = User::factory()->withRole(Role::SalesAdmin)->create();
    }

    public function test_properties_under_one_legal_account_share_one_set_of_points(): void
    {
        foreach ([10, 30, 50] as $rooms) {
            $this->liveProvider(['account_id' => 'H-100', 'legal_name' => 'Coral Bay Hospitality Ltd', 'inventory_count' => $rooms]);
        }

        $this->sync();

        $account = PartnerAccount::sole();
        $this->assertSame('Coral Bay Hospitality Ltd', $account->legal_name);
        $this->assertSame(90, $account->activation_inventory);
        $this->assertCount(3, $account->onboardings);

        $entry = PointEntry::counting()->sole();
        $this->assertSame(5.0, $entry->points);
        $this->assertSame('provisional', $entry->status);
        $this->assertTrue($entry->user->is($this->mary));
        $this->assertSame(2, $entry->bonus_week);
    }

    public function test_separate_legal_accounts_earn_separately_and_experiences_use_the_service_table(): void
    {
        $this->liveProvider(['account_id' => 'H-1', 'inventory_count' => 40, 'property_type' => 'hotel']);
        $this->liveProvider(['account_id' => 'H-2', 'inventory_count' => 18, 'property_type' => 'tour']);

        $this->sync();

        $this->assertSame(2, PartnerAccount::count());
        $this->assertSame('experience', PartnerAccount::where('account_key', 'A:H-2')->value('category'));
        $this->assertEqualsCanonicalizing([3.0, 3.0], PointEntry::pluck('points')->all());
    }

    public function test_points_are_approved_only_after_the_full_checklist_is_verified(): void
    {
        $this->liveProvider(['account_id' => 'H-1', 'inventory_count' => 40]);
        $this->sync();
        $account = PartnerAccount::sole();

        try {
            app(AccountPoints::class)->verify($account, $this->admin, 40, 'rooms');
            $this->fail('Verification should need a complete checklist.');
        } catch (ValidationException) {
            $this->assertSame('provisional', PointEntry::sole()->status);
        }

        $this->completeChecklist($account);
        app(AccountPoints::class)->verify($account->fresh(), $this->admin, 55, 'rooms');

        $this->assertSame('cancelled', PointEntry::orderBy('id')->first()->status);
        $current = PointEntry::where('status', '!=', 'cancelled')->sole();
        $this->assertSame(5.0, $current->points);
        $this->assertSame('approved', $current->status);
    }

    public function test_a_higher_category_expansion_earns_the_difference(): void
    {
        $account = $this->verifiedAccount(90);

        app(AccountPoints::class)->recordInventory($account, $this->admin, 120, CarbonImmutable::parse('2026-09-20 10:00'), 'New branch live');

        $expansion = PointEntry::where('type', 'expansion')->sole();
        $this->assertSame(2.0, $expansion->points);
        $this->assertSame(3, $expansion->bonus_week);
        $this->assertSame(7.0, $account->fresh()->livePoints());
    }

    public function test_one_half_point_for_fifty_percent_growth_in_the_same_category(): void
    {
        $account = $this->verifiedAccount(20);

        app(AccountPoints::class)->recordInventory($account, $this->admin, 29, now()->toImmutable(), 'Not yet +50%');
        app(AccountPoints::class)->recordInventory($account, $this->admin, 30, now()->toImmutable()->addMinute(), '+50%');
        app(AccountPoints::class)->recordInventory($account, $this->admin, 48, now()->toImmutable()->addMinutes(2), 'Grew again');

        $this->assertSame([0.5], PointEntry::where('type', 'half')->where('status', '!=', 'cancelled')->pluck('points')->all());
        $this->assertSame(3.5, $account->fresh()->livePoints());
    }

    public function test_stay_accounts_stop_at_nine_base_points(): void
    {
        $account = $this->verifiedAccount(150);

        app(AccountPoints::class)->recordInventory($account, $this->admin, 300, now()->toImmutable(), 'Big expansion');
        app(AccountPoints::class)->recordInventory($account, $this->admin, 600, now()->toImmutable()->addMinute(), 'Bigger');

        $this->assertSame(9.0, $account->fresh()->livePoints());
    }

    public function test_expansion_after_ninety_days_or_after_the_agreement_ends_earns_nothing(): void
    {
        $account = $this->verifiedAccount(90);

        app(AccountPoints::class)->recordInventory($account, $this->admin, 150, CarbonImmutable::parse('2026-12-20'), 'Too late');
        $this->assertSame(0, PointEntry::where('type', 'expansion')->count());

        IncentiveAgreement::query()->update(['ends_on' => '2026-09-15']);
        app(AccountPoints::class)->recordInventory($account, $this->admin, 150, CarbonImmutable::parse('2026-09-20'), 'After agreement ended');
        $this->assertSame(0, PointEntry::where('type', 'expansion')->count());
    }

    public function test_failing_the_review_cancels_every_point(): void
    {
        $account = $this->verifiedAccount(90);
        app(AccountPoints::class)->recordInventory($account, $this->admin, 120, now()->toImmutable(), 'Branch');

        app(AccountPoints::class)->failReview($account->fresh(), $this->admin, 'Partner requested closure');

        $this->assertSame(0, PointEntry::counting()->count());
        $this->assertStringStartsWith('Failed 14-day review', PointEntry::first()->reason);
    }

    public function test_the_review_can_only_fail_within_fourteen_days(): void
    {
        $account = $this->verifiedAccount(40);
        $this->travelTo(now()->addDays(15));

        $this->expectException(ValidationException::class);
        app(AccountPoints::class)->failReview($account->fresh(), $this->admin, 'Too late to fail');
    }

    public function test_a_rejection_reported_during_review_raises_a_warning(): void
    {
        $provider = $this->liveProvider(['account_id' => 'H-9', 'inventory_count' => 40]);
        $this->sync();

        $provider->update(['status' => 'rejected', 'rejected_at' => now()]);
        $this->sync();

        $this->assertNotNull(PartnerAccount::sole()->review_warning_at);
    }

    public function test_merging_moves_properties_and_recalculates(): void
    {
        $this->liveProvider(['account_id' => 'H-1', 'inventory_count' => 30]);
        $this->liveProvider(['account_id' => 'H-2', 'inventory_count' => 40]);
        $this->sync();
        [$target, $source] = PartnerAccount::orderBy('id')->get()->all();

        app(AccountPoints::class)->merge($source, $target, $this->admin);

        $target->refresh();
        $this->assertSame(70, $target->activation_inventory);
        $this->assertSame(5.0, $target->livePoints());
        $this->assertSame(0.0, $source->fresh()->livePoints());
        $this->assertSame($target->id, $source->fresh()->merged_into_id);
    }

    public function test_sales_admin_verifies_from_the_account_page_and_salespeople_cannot(): void
    {
        $this->liveProvider(['account_id' => 'H-1', 'inventory_count' => 40]);
        $this->sync();
        $account = PartnerAccount::sole();
        $this->completeChecklist($account);

        Livewire::actingAs($this->mary)->test(Show::class, ['account' => $account])->call('openVerify')->assertForbidden();

        Livewire::actingAs($this->admin)->test(Show::class, ['account' => $account])
            ->call('openVerify')
            ->set('verifyInventory', '45')
            ->call('verify')
            ->assertHasNoErrors();

        $this->assertTrue($account->fresh()->isVerified());

        $other = User::factory()->withRole(Role::Salesperson)->create();
        $this->actingAs($other)->get(route('accounts.show', $account))->assertForbidden();
        $this->actingAs($this->mary)->get(route('accounts.show', $account))->assertOk()->assertSee('Qualification checklist');
    }

    private function verifiedAccount(int $rooms): PartnerAccount
    {
        $this->liveProvider(['account_id' => 'H-'.$rooms, 'inventory_count' => $rooms]);
        $this->sync();
        $account = PartnerAccount::where('account_key', 'A:H-'.$rooms)->sole();
        $this->completeChecklist($account);

        return app(AccountPoints::class)->verify($account->fresh(), $this->admin, $rooms, 'rooms');
    }

    private function completeChecklist(PartnerAccount $account): void
    {
        foreach (ChecklistItem::cases() as $item) {
            $account->checklistItems()->updateOrCreate(['item' => $item->value], ['completed_at' => now(), 'source' => $item->isAutomatic() ? 'auto' : 'manual']);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function liveProvider(array $attributes): SandboxProvider
    {
        return SandboxProvider::factory()->create([
            'ref_code' => $this->mary->referralCode->code,
            'status' => 'active',
            'approved_at' => now()->subDay(),
            'active_at' => now(),
            'property_type' => 'hotel',
            ...$attributes,
        ]);
    }

    private function sync(): void
    {
        app(SyncOnboardings::class)->handle('full');
    }
}
