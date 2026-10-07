<?php

namespace Tests\Feature;

use App\Actions\ApplyProviderRecord;
use App\Actions\IssueReferralCode;
use App\Actions\UpdateReferralCode;
use App\Enums\Role;
use App\Integrations\Tourlast\ProviderRecordMapper;
use App\Models\Onboarding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplyProviderRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $mary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        app(IssueReferralCode::class)->handle($this->mary);
        $this->mary->refresh();
    }

    /**
     * A feed row as an Experiences app sends it, keys in the app's own order.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function feedRow(array $overrides = []): array
    {
        return [
            'property_id' => 'exp-01',
            'account_id' => 'exp-01',
            'legal_name' => 'Safari Kenya Tours',
            'property_name' => 'Safari Kenya Tours',
            'property_type' => 'experience',
            'category' => 'experience',
            'inventory_count' => 8,
            'ref_code' => null,
            'location' => 'Nairobi, Kenya',
            'contact_name' => 'Wanjiku Kamau',
            'contact_email' => 'safari@example.com',
            'contact_phone' => '+254 712 345 678',
            'status' => 'active',
            'submitted_at' => '2026-10-02T08:43:27+00:00',
            'approved_at' => '2026-10-02T08:43:27+00:00',
            'active_at' => '2026-10-02T08:43:27+00:00',
            'inactive_at' => null,
            'rejected_at' => null,
            'first_booking_at' => '2026-10-02T08:43:38+00:00',
            'is_deleted' => false,
            'deleted_at' => null,
            'updated_at' => '2026-10-02T08:43:27+00:00',
            ...$overrides,
        ];
    }

    private function apply(array $row): string
    {
        return app(ApplyProviderRecord::class)->handle(app(ProviderRecordMapper::class)->fromArray($row), 'sync');
    }

    /**
     * What MySQL does to a JSON column: it hands the keys back sorted by length, then alphabetically.
     */
    private function storeAsMysqlWould(Onboarding $onboarding): void
    {
        $payload = $onboarding->source_payload;
        uksort($payload, fn (string $a, string $b): int => [strlen($a), $a] <=> [strlen($b), $b]);

        DB::table('onboardings')->where('id', $onboarding->id)->update(['source_payload' => json_encode($payload)]);
    }

    public function test_the_same_row_twice_is_unchanged_the_second_time(): void
    {
        $this->assertSame(ApplyProviderRecord::Created, $this->apply($this->feedRow()));
        $this->assertSame(ApplyProviderRecord::Unchanged, $this->apply($this->feedRow()));
    }

    public function test_an_unchanged_row_is_not_an_update_when_the_database_returns_the_json_keys_in_another_order(): void
    {
        $this->apply($this->feedRow());
        $this->storeAsMysqlWould(Onboarding::sole());

        $this->assertSame(ApplyProviderRecord::Unchanged, $this->apply($this->feedRow()));
    }

    public function test_a_changed_field_still_updates_even_when_the_stored_json_is_in_another_order(): void
    {
        $this->apply($this->feedRow());
        $this->storeAsMysqlWould(Onboarding::sole());

        $this->assertSame(ApplyProviderRecord::Updated, $this->apply($this->feedRow(['contact_phone' => '+254 700 000 000', 'updated_at' => '2026-10-03T08:00:00+00:00'])));
        $this->assertSame('+254 700 000 000', Onboarding::sole()->contact_phone);
        $this->assertSame('+254 700 000 000', Onboarding::sole()->source_payload['contact_phone']);
    }

    public function test_a_change_that_only_lives_in_the_raw_payload_is_still_stored(): void
    {
        $this->apply($this->feedRow());

        $this->assertSame(ApplyProviderRecord::Updated, $this->apply($this->feedRow(['extra_flag' => true, 'updated_at' => '2026-10-03T08:00:00+00:00'])));
        $this->assertTrue(Onboarding::sole()->source_payload['extra_flag']);
    }

    public function test_renaming_a_referral_code_does_not_remove_credit_when_a_source_app_still_sends_the_old_code(): void
    {
        $oldCode = $this->mary->referralCode->code;

        $this->apply($this->feedRow(['ref_code' => $oldCode]));
        $this->assertSame($this->mary->id, Onboarding::sole()->user_id);

        app(UpdateReferralCode::class)->handle($this->mary, 'TL-MARY-NEW');

        $this->apply($this->feedRow(['ref_code' => $oldCode, 'updated_at' => '2026-10-03T08:00:00+00:00']));

        $onboarding = Onboarding::sole();
        $this->assertSame($this->mary->id, $onboarding->user_id);
        $this->assertNotNull($onboarding->referral_code_id);
    }

    public function test_a_new_property_with_an_unknown_code_stays_unattributed(): void
    {
        $this->apply($this->feedRow(['ref_code' => 'TL-NOBODY-1']));

        $onboarding = Onboarding::sole();
        $this->assertNull($onboarding->user_id);
        $this->assertNull($onboarding->referral_code_id);
    }

    public function test_a_known_code_still_re_points_credit_to_its_new_owner(): void
    {
        $other = User::factory()->withRole(Role::Salesperson)->create();
        app(IssueReferralCode::class)->handle($other);

        $this->apply($this->feedRow(['ref_code' => $this->mary->referralCode->code]));
        $this->apply($this->feedRow(['ref_code' => $other->fresh()->referralCode->code, 'updated_at' => '2026-10-03T08:00:00+00:00']));

        $this->assertSame($other->id, Onboarding::sole()->user_id);
    }
}
