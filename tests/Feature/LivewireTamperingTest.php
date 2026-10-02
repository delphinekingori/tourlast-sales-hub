<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Livewire\Admin\Incentives;
use App\Livewire\Claims\Approvals;
use App\Livewire\Claims\Index as ClaimsIndex;
use App\Livewire\Onboardings\Mine;
use App\Livewire\Onboardings\Unattributed;
use App\Livewire\Payouts\Index as PayoutsIndex;
use App\Livewire\Team\Index as TeamIndex;
use App\Models\ExpenseClaim;
use App\Models\Onboarding;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LivewireTamperingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $mary;

    private User $john;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole(Role::SuperAdmin)->create();
        $this->mary = User::factory()->withRole(Role::Salesperson)->create();
        $this->john = User::factory()->withRole(Role::Salesperson)->create();
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2?: bool}>
     */
    public static function lockedRecordIds(): array
    {
        return [
            'incentive agreement' => [Incentives::class, 'editingId'],
            'claim approvals' => [Approvals::class, 'viewingId'],
            'my claims' => [ClaimsIndex::class, 'viewingId', true],
            'my onboardings' => [Mine::class, 'viewingId', true],
            'unattributed onboardings' => [Unattributed::class, 'assigningId'],
            'payouts' => [PayoutsIndex::class, 'viewingId'],
            'team member edit' => [TeamIndex::class, 'editingUserId'],
        ];
    }

    #[DataProvider('lockedRecordIds')]
    public function test_a_record_id_cannot_be_changed_from_the_browser(string $component, string $property, bool $asSalesperson = false): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($asSalesperson ? $this->mary : $this->admin)->test($component)->set($property, 1);
    }

    public function test_a_salesperson_cannot_open_another_salespersons_claim(): void
    {
        $johnsClaim = ExpenseClaim::factory()->create(['user_id' => $this->john->id, 'description' => 'Johns private trip']);

        Livewire::actingAs($this->mary)->test(ClaimsIndex::class)
            ->call('view', $johnsClaim->id)
            ->assertNotFound();
    }

    public function test_a_salesperson_cannot_open_another_salespersons_onboarding(): void
    {
        $johns = Onboarding::factory()->forSalesperson($this->john)->create(['property_name' => 'Johns Secret Lodge']);

        Livewire::actingAs($this->mary)->test(Mine::class)
            ->call('view', $johns->id)
            ->assertNotFound();
    }

    public function test_the_claim_detail_lookup_is_scoped_to_the_signed_in_user(): void
    {
        $claim = ExpenseClaim::factory()->create(['user_id' => $this->mary->id, 'description' => 'Marys own trip']);

        $component = Livewire::actingAs($this->mary)->test(ClaimsIndex::class)->call('view', $claim->id);
        $this->assertTrue($component->viewData('viewing')->is($claim));

        $claim->update(['user_id' => $this->john->id]);

        $component->call('$refresh');
        $this->assertNull($component->viewData('viewing'));
        $component->assertDontSee('Marys own trip');
    }

    public function test_the_onboarding_detail_lookup_is_scoped_to_the_signed_in_user(): void
    {
        $onboarding = Onboarding::factory()->forSalesperson($this->mary)->create(['property_name' => 'Marys Own Lodge']);

        $component = Livewire::actingAs($this->mary)->test(Mine::class)->call('view', $onboarding->id);
        $this->assertTrue($component->viewData('viewing')->is($onboarding));

        $onboarding->update(['user_id' => $this->john->id]);

        $component->call('$refresh');
        $this->assertNull($component->viewData('viewing'));
    }
}
