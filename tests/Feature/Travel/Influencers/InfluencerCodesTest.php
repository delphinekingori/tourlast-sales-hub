<?php

namespace Tests\Feature\Travel\Influencers;

use App\Actions\Travel\Influencers\SaveInfluencerCode;
use App\Actions\Travel\Influencers\SuggestInfluencerCode;
use App\Enums\Role;
use App\Enums\Travel\CommissionEntryStatus;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Livewire\Travel\Influencers\Index;
use App\Livewire\Travel\Influencers\Show;
use App\Models\AuditEvent;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use App\Models\InfluencerCommission;
use App\Models\PackageBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InfluencerCodesTest extends TestCase
{
    use RefreshDatabase;

    private function travelSeller(): User
    {
        return User::factory()->withRole(Role::TravelSalesperson)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function terms(array $overrides = []): array
    {
        return [
            'code' => 'AMINA10',
            'commission_type' => 'percentage',
            'commission_value' => '10',
            'applies_to' => 'packages',
            'max_bookings' => '5',
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->addMonth()->toDateString(),
            ...$overrides,
        ];
    }

    public function test_a_travel_salesperson_adds_an_influencer_and_generates_a_code(): void
    {
        $seller = $this->travelSeller();

        Livewire::actingAs($seller)->test(Index::class)
            ->call('newInfluencer')
            ->set('influencerForm.name', 'Amina Wanjiru')
            ->set('influencerForm.platforms.0.handle', '@amina')
            ->call('saveInfluencer')
            ->assertHasNoErrors()
            ->assertSet('showCodeForm', true)
            ->assertSet('codeForm.code', 'AMINA10')
            ->set('codeForm.commission_value', '12.5')
            ->set('codeForm.max_bookings', '20')
            ->call('saveCode')
            ->assertHasNoErrors()
            ->assertSee('AMINA10')
            ->assertSee('12.5% per booking, first 20 bookings');

        $influencer = Influencer::query()->sole();
        $this->assertSame($seller->id, $influencer->owner_id);
        $this->assertSame($seller->id, InfluencerCode::query()->sole()->created_by);
        $this->assertTrue(AuditEvent::query()->where('action', 'influencer.code_created')->exists());
    }

    public function test_codes_must_be_unique_ignoring_case_and_well_formed(): void
    {
        $seller = $this->travelSeller();
        $influencer = Influencer::factory()->create(['owner_id' => $seller->id]);
        InfluencerCode::factory()->create(['code' => 'AMINA10']);

        Livewire::actingAs($seller)->test(Index::class)
            ->call('newCode', $influencer->id)
            ->set('codeForm.code', 'amina10')
            ->set('codeForm.commission_value', '10')
            ->call('saveCode')
            ->assertHasErrors(['codeForm.code'])
            ->set('codeForm.code', 'AB!')
            ->call('saveCode')
            ->assertHasErrors(['codeForm.code']);

        $this->assertSame(1, InfluencerCode::query()->count());
    }

    public function test_commission_value_limits_depend_on_the_type(): void
    {
        $seller = $this->travelSeller();
        $influencer = Influencer::factory()->create(['owner_id' => $seller->id]);
        $save = app(SaveInfluencerCode::class);

        foreach ([['percentage', '60'], ['percentage', '0.2'], ['fixed', '0']] as [$type, $value]) {
            try {
                $save->handle($seller, $influencer, $this->terms(['commission_type' => $type, 'commission_value' => $value]));
                $this->fail("{$type} {$value} should be rejected");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('commission_value', $exception->errors());
            }
        }

        $save->handle($seller, $influencer, $this->terms(['commission_type' => 'fixed', 'commission_value' => '1500']));
        $this->assertSame('KES 1,500 per booking, first 5 bookings', InfluencerCode::query()->sole()->termsLabel());
    }

    public function test_suggested_codes_skip_taken_ones(): void
    {
        $influencer = Influencer::factory()->create(['name' => 'Amina Wanjiru', 'handle' => null]);
        InfluencerCode::factory()->create(['code' => 'AMINA10']);

        $this->assertSame('AMINA15', app(SuggestInfluencerCode::class)->handle($influencer));
    }

    public function test_salespeople_only_see_and_change_their_own_influencers(): void
    {
        $mine = $this->travelSeller();
        $theirs = Influencer::factory()->create(['name' => 'Someone Else']);
        $code = InfluencerCode::factory()->create(['influencer_id' => $theirs->id, 'code' => 'OTHER10']);

        Livewire::actingAs($mine)->test(Index::class)->assertDontSee('OTHER10')->assertDontSee('Someone Else');

        $this->actingAs($mine)->get(route('travel.influencers.show', $theirs))->assertNotFound();

        Livewire::actingAs($mine)->test(Index::class)->call('setCodeStatus', $code->id, 'paused')->assertNotFound();
        $this->assertSame(InfluencerCodeStatus::Active, $code->fresh()->status);

        $this->expectException(HttpException::class);
        app(SaveInfluencerCode::class)->handle($mine, $theirs, $this->terms());
    }

    public function test_travel_managers_see_everyone(): void
    {
        $code = InfluencerCode::factory()->create(['code' => 'SEEN10']);
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        Livewire::actingAs($admin)->test(Index::class)->assertSee('SEEN10');
        $this->actingAs($admin)->get(route('travel.influencers.show', $code->influencer))->assertOk();
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function excludedRoles(): array
    {
        return ['Sales Manager' => [Role::SalesManager], 'HR' => [Role::Hr], 'Salesperson' => [Role::Salesperson]];
    }

    #[DataProvider('excludedRoles')]
    public function test_other_roles_cannot_open_the_module(Role $role): void
    {
        $code = InfluencerCode::factory()->create();
        $user = User::factory()->withRole($role)->create();

        $this->actingAs($user)->get(route('travel.influencers.index'))->assertForbidden();
        $this->actingAs($user)->get(route('travel.influencers.show', $code->influencer))->assertForbidden();
        $this->actingAs($user)->get(route('travel.influencers.export'))->assertForbidden();
    }

    public function test_accounts_can_view_and_mark_paid_but_not_create(): void
    {
        $accounts = User::factory()->withRole(Role::Accounts)->create();
        $code = InfluencerCode::factory()->create(['code' => 'PAYME10']);
        $line = $this->payableLine($code);

        Livewire::actingAs($accounts)->test(Index::class)->assertSee('PAYME10')->call('newInfluencer')->assertForbidden();

        $this->expectsNoForbiddenOnShow($accounts, $code->influencer);

        Livewire::actingAs($accounts)->test(Show::class, ['influencer' => $code->influencer_id])
            ->set('selected', [$line->id])
            ->call('openPay')
            ->set('payReference', '')
            ->call('markPaid')
            ->assertHasErrors(['payReference'])
            ->set('payReference', 'SJK3H7Q2LP')
            ->call('markPaid')
            ->assertHasNoErrors();

        $line->refresh();
        $this->assertSame(CommissionEntryStatus::Paid, $line->status);
        $this->assertSame('SJK3H7Q2LP', $line->payment_reference);
        $this->assertSame($accounts->id, $line->paid_by);
        $this->assertTrue(AuditEvent::query()->where('action', 'influencer.commission_paid')->exists());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $code->influencer->owner_id]);
    }

    public function test_salespeople_cannot_mark_commission_paid(): void
    {
        $code = InfluencerCode::factory()->create();
        $line = $this->payableLine($code);

        Livewire::actingAs($code->influencer->owner)->test(Show::class, ['influencer' => $code->influencer_id])
            ->set('selected', [$line->id])
            ->call('openPay')
            ->assertForbidden();

        $this->assertSame(CommissionEntryStatus::Payable, $line->fresh()->status);
    }

    public function test_payout_details_are_hidden_from_people_who_should_not_see_them(): void
    {
        $influencer = Influencer::factory()->create(['payout_details' => '0711 222333 Amina']);

        $this->actingAs($influencer->owner)->get(route('travel.influencers.show', $influencer))->assertSee('0711 222333 Amina');
        $this->actingAs(User::factory()->withRole(Role::Accounts)->create())->get(route('travel.influencers.show', $influencer))->assertSee('0711 222333 Amina');
        $this->assertNotSame('0711 222333 Amina', DB::table('influencers')->value('payout_details'));
    }

    public function test_terms_are_locked_once_a_code_has_earned(): void
    {
        $code = InfluencerCode::factory()->create();
        $this->payableLine($code);

        $this->expectException(ValidationException::class);
        app(SaveInfluencerCode::class)->handle($code->influencer->owner, $code->influencer, $this->terms(['code' => $code->code, 'commission_value' => '20']), $code);
    }

    public function test_codes_can_be_paused_resumed_and_ended(): void
    {
        $code = InfluencerCode::factory()->create();

        Livewire::actingAs($code->influencer->owner)->test(Index::class)
            ->call('setCodeStatus', $code->id, 'paused')
            ->call('setCodeStatus', $code->id, 'active')
            ->call('setCodeStatus', $code->id, 'ended');

        $this->assertSame(InfluencerCodeStatus::Ended, $code->fresh()->status);
        $this->assertTrue($code->fresh()->ends_on->isToday());

        Livewire::actingAs($code->influencer->owner)->test(Index::class)
            ->call('setCodeStatus', $code->id, 'active')
            ->assertHasErrors(['status']);
    }

    public function test_the_table_export_downloads(): void
    {
        InfluencerCode::factory()->create();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        $this->actingAs($admin)->get(route('travel.influencers.export'))->assertOk()->assertDownload();
    }

    private function payableLine(InfluencerCode $code): InfluencerCommission
    {
        $booking = PackageBooking::factory()->paid()->create(['influencer_code_id' => $code->id]);

        return InfluencerCommission::query()->create([
            'influencer_code_id' => $code->id,
            'influencer_id' => $code->influencer_id,
            'bookable_type' => $booking->getMorphClass(),
            'bookable_id' => $booking->id,
            'booking_amount' => 90000,
            'commission_amount' => 9000,
            'status' => CommissionEntryStatus::Payable,
            'earned_at' => now(),
        ]);
    }

    private function expectsNoForbiddenOnShow(User $user, Influencer $influencer): void
    {
        $this->actingAs($user)->get(route('travel.influencers.show', $influencer))->assertOk();
    }
}
