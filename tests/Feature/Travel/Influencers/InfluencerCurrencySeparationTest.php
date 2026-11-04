<?php

namespace Tests\Feature\Travel\Influencers;

use App\Actions\Travel\Influencers\MarkCommissionsPaid;
use App\Enums\Role;
use App\Enums\Travel\CommissionEntryStatus;
use App\Models\FlightBooking;
use App\Models\InfluencerCode;
use App\Models\InfluencerCommission;
use App\Models\PackageBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InfluencerCurrencySeparationTest extends TestCase
{
    use RefreshDatabase;

    private function line(InfluencerCode $code, PackageBooking|FlightBooking $booking, string $currency, float $amount): InfluencerCommission
    {
        return InfluencerCommission::query()->create([
            'influencer_code_id' => $code->id,
            'influencer_id' => $code->influencer_id,
            'bookable_type' => $booking->getMorphClass(),
            'bookable_id' => $booking->id,
            'booking_amount' => 1000,
            'commission_amount' => $amount,
            'currency' => $currency,
            'status' => CommissionEntryStatus::Payable,
            'earned_at' => now(),
        ]);
    }

    public function test_kes_and_usd_commission_cannot_be_marked_paid_together(): void
    {
        $code = InfluencerCode::factory()->create();
        $kes = $this->line($code, PackageBooking::factory()->paid()->create(['influencer_code_id' => $code->id]), 'KES', 9000);
        $usd = $this->line($code, FlightBooking::factory()->create(['influencer_code_id' => $code->id]), 'USD', 40);
        $accounts = User::factory()->withRole(Role::Accounts)->create();

        try {
            app(MarkCommissionsPaid::class)->handle($accounts, $code->influencer, [$kes->id, $usd->id], 'REF1');
            $this->fail('Mixed currencies should be refused');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selected', $exception->errors());
        }

        $this->assertSame(CommissionEntryStatus::Payable, $kes->fresh()->status);

        app(MarkCommissionsPaid::class)->handle($accounts, $code->influencer, [$usd->id], 'REF2');
        $this->assertSame(CommissionEntryStatus::Paid, $usd->fresh()->status);
        $this->assertSame(CommissionEntryStatus::Payable, $kes->fresh()->status);
    }

    public function test_the_influencer_page_shows_each_currency_separately(): void
    {
        $code = InfluencerCode::factory()->create();
        $this->line($code, PackageBooking::factory()->paid()->create(['influencer_code_id' => $code->id]), 'KES', 9000);
        $this->line($code, FlightBooking::factory()->create(['influencer_code_id' => $code->id]), 'USD', 40);
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        $this->actingAs($admin)->get(route('travel.influencers.show', $code->influencer))
            ->assertOk()
            ->assertSee('KES 9,000.00')
            ->assertSee('USD 40.00');

        $this->actingAs($admin)->get(route('travel.influencers.index'))
            ->assertOk()
            ->assertSee('KES 9,000')
            ->assertSee('USD 40');
    }
}
