<?php

namespace Tests\Feature\Travel\Providers;

use App\Actions\Travel\Providers\TransitionContract;
use App\Enums\Role;
use App\Enums\Travel\ContractStatus;
use App\Livewire\Travel\Contracts\Show as ContractShow;
use App\Livewire\Travel\Providers\Show as ProviderShow;
use App\Models\AuditEvent;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ContractWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesperson_drafts_and_submits_a_contract_and_a_manager_approves_it(): void
    {
        $provider = TravelProvider::factory()->create();
        $owner = $provider->owner;

        Livewire::actingAs($owner)->test(ProviderShow::class, ['provider' => $provider->id])
            ->call('openContract', $provider->id)
            ->set('contractForm.contract_type', 'Net rate agreement')
            ->set('contractForm.commission_rate', '15')
            ->set('contractForm.cancellation_terms', 'Free cancellation 14 days out.')
            ->call('saveContract')
            ->assertHasNoErrors();

        $contract = ProviderContract::query()->firstOrFail();
        $this->assertSame(ContractStatus::Draft, $contract->status);
        $this->assertMatchesRegularExpression('/^TL-\d{4}-\d{3}$/', $contract->contract_number);
        $this->assertSame('15.00', $contract->commission_rate);

        $transition = app(TransitionContract::class);
        $transition->handle($contract, 'submit_review', $owner);
        $transition->handle($contract->fresh(), 'submit_approval', $owner);

        // The salesperson cannot approve, nor edit once it is awaiting approval.
        $this->assertForbidden(fn () => $transition->handle($contract->fresh(), 'approve', $owner));
        Livewire::actingAs($owner)->test(ContractShow::class, ['contract' => $contract->id])
            ->call('openContract', $provider->id, $contract->id)
            ->assertForbidden();

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $transition->handle($contract->fresh(), 'approve', $admin);

        $contract->refresh();
        $this->assertSame(ContractStatus::Active, $contract->status);
        $this->assertSame($admin->id, $contract->approved_by);
        $this->assertNotNull($contract->approved_at);
        $this->assertSame(4, AuditEvent::query()->where('subject_type', $contract->getMorphClass())->where('subject_id', $contract->id)->count());
    }

    public function test_whoever_wrote_a_contract_cannot_approve_it_except_a_super_admin(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $contract = ProviderContract::factory()->create(['status' => ContractStatus::PendingApproval, 'created_by' => $admin->id]);

        $this->assertForbidden(fn () => app(TransitionContract::class)->handle($contract, 'approve', $admin));

        $super = User::factory()->withRole(Role::SuperAdmin)->create();
        $own = ProviderContract::factory()->create(['status' => ContractStatus::PendingApproval, 'created_by' => $super->id]);
        app(TransitionContract::class)->handle($own, 'approve', $super);
        $this->assertSame(ContractStatus::Active, $own->fresh()->status);
    }

    public function test_suspending_and_terminating_need_a_manager_and_a_reason(): void
    {
        $contract = ProviderContract::factory()->create();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        $this->assertForbidden(fn () => app(TransitionContract::class)->handle($contract, 'suspend', $contract->provider->owner, 'Late payments'));

        try {
            app(TransitionContract::class)->handle($contract, 'terminate', $admin);
            $this->fail('A reason should be required.');
        } catch (ValidationException) {
            $this->assertSame(ContractStatus::Active, $contract->fresh()->status);
        }

        Livewire::actingAs($admin)->test(ContractShow::class, ['contract' => $contract->id])
            ->call('openTransition', 'terminate')
            ->set('transitionNote', 'Provider closed down')
            ->call('applyTransition')
            ->assertHasNoErrors();

        $this->assertSame(ContractStatus::Terminated, $contract->fresh()->status);
    }

    public function test_commission_is_hidden_from_salespeople_who_do_not_own_the_provider(): void
    {
        $contract = ProviderContract::factory()->create(['commission_rate' => 17.5]);
        $stranger = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->actingAs($stranger)->get(route('travel.contracts.show', $contract->id))->assertOk()->assertDontSee('17.5%')->assertSee('Restricted');
        $this->actingAs($contract->provider->owner)->get(route('travel.contracts.show', $contract->id))->assertSee('17.5%');
        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->get(route('travel.contracts.index'))->assertSee('17.5%');
        $this->actingAs($stranger)->get(route('travel.contracts.index'))->assertDontSee('17.5%');
    }

    public function test_documents_are_stored_privately_and_downloaded_only_by_allowed_people(): void
    {
        Storage::fake('local');
        $contract = ProviderContract::factory()->create();
        $owner = $contract->provider->owner;

        Livewire::actingAs($owner)->test(ContractShow::class, ['contract' => $contract->id])
            ->set('documentType', 'rate_sheet')
            ->set('document', UploadedFile::fake()->create('rates-2026.pdf', 200, 'application/pdf'))
            ->call('uploadDocument')
            ->assertHasNoErrors();

        $document = $contract->documents()->firstOrFail();
        Storage::disk('local')->assertExists($document->path);
        $this->assertStringStartsWith('travel/contracts/'.$contract->id.'/', $document->path);

        $this->actingAs($owner)->get(route('travel.contracts.document', $document->id))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->get(route('travel.contracts.document', $document->id))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())->get(route('travel.contracts.document', $document->id))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::SalesManager)->create())->get(route('travel.contracts.document', $document->id))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::Hr)->create())->get(route('travel.contracts.document', $document->id))->assertForbidden();
    }

    public function test_the_contracts_list_filters_by_expiry(): void
    {
        $soon = ProviderContract::factory()->endingIn(10)->create(['contract_number' => 'TL-2026-901']);
        $expired = ProviderContract::factory()->expired()->create(['contract_number' => 'TL-2026-902']);
        $fine = ProviderContract::factory()->create(['contract_number' => 'TL-2026-903']);

        $this->assertSame(ContractStatus::ExpiringSoon, $soon->effectiveStatus());
        $this->assertSame(ContractStatus::Expired, $expired->effectiveStatus());

        $user = User::factory()->withRole(Role::TravelSalesperson)->create();
        $this->actingAs($user)->get(route('travel.contracts.index', ['status' => 'expiring_soon']))
            ->assertSee('TL-2026-901')->assertDontSee('TL-2026-902')->assertDontSee('TL-2026-903');
        $this->actingAs($user)->get(route('travel.contracts.index', ['status' => 'expired']))
            ->assertSee('TL-2026-902')->assertDontSee('TL-2026-901');
    }

    private function assertForbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
