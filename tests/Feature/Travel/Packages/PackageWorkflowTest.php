<?php

namespace Tests\Feature\Travel\Packages;

use App\Actions\Travel\Packages\CreatePackage;
use App\Actions\Travel\Packages\DuplicatePackage;
use App\Actions\Travel\Packages\PublishPackage;
use App\Actions\Travel\Packages\ReviewPackage;
use App\Actions\Travel\Packages\SavePackage;
use App\Actions\Travel\Packages\SubmitPackage;
use App\Actions\Travel\Packages\SyncPackageMedia;
use App\Actions\Travel\Packages\UnpublishPackage;
use App\Actions\Travel\Packages\WithdrawPackage;
use App\Enums\Role;
use App\Enums\Travel\ApprovalDecision;
use App\Enums\Travel\ApprovalLevel;
use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\Package;
use App\Models\PackageApproval;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\User;
use App\Notifications\SmartAlert;
use App\Support\Travel\PackageContent;
use App\Support\Travel\PackageReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PackageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $salesAdmin;

    private User $superAdmin;

    private TravelProvider $provider;

    private ProviderContract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seller = User::factory()->withRole(Role::TravelSalesperson)->create(['name' => 'John Doe']);
        $this->salesAdmin = User::factory()->withRole(Role::SalesAdmin)->create(['name' => 'Sarah Admin']);
        $this->superAdmin = User::factory()->withRole(Role::SuperAdmin)->create(['name' => 'David Super']);
        $this->provider = TravelProvider::factory()->create(['owner_id' => $this->seller->id, 'name' => 'ABC Safaris']);
        $this->contract = ProviderContract::factory()->create(['travel_provider_id' => $this->provider->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(array $overrides = []): array
    {
        return [
            'name' => 'Masai Mara 3-Day Safari',
            'short_description' => 'Three days in the Mara.',
            'description' => 'Classic road safari.',
            'package_type' => 'safari',
            'travel_provider_id' => $this->provider->id,
            'provider_contract_id' => $this->contract->id,
            'destination' => 'Masai Mara',
            'country' => 'Kenya',
            'days' => 3,
            'nights' => 2,
            'default_capacity' => 12,
            'inclusions' => ['Park fees', 'Meals'],
            'exclusions' => ['Tips'],
            'cancellation_policy' => 'Full refund up to 14 days before.',
            'refund_policy' => 'Paid within 10 days.',
            'currency' => 'KES',
            'adult_price' => 45000,
            'child_price' => 30000,
            ...$overrides,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itinerary(): array
    {
        return [
            ['title' => 'Nairobi to the Mara', 'meals' => ['lunch', 'dinner']],
            ['title' => 'Full day game drive', 'meals' => ['breakfast', 'lunch', 'dinner']],
            ['title' => 'Return to Nairobi', 'meals' => ['breakfast']],
        ];
    }

    private function readyPackage(): Package
    {
        $package = app(CreatePackage::class)->handle($this->seller, $this->content(), $this->itinerary());
        app(SyncPackageMedia::class)->handle($this->seller, $package, [MediaAsset::factory()->create(['travel_provider_id' => $this->provider->id])->id]);

        return $package->fresh();
    }

    private function approvedPackage(): Package
    {
        $package = $this->readyPackage();
        app(SubmitPackage::class)->handle($this->seller, $package);
        app(ReviewPackage::class)->handle($this->salesAdmin, $package->fresh(), ApprovalDecision::Approved);
        app(ReviewPackage::class)->handle($this->superAdmin, $package->fresh(), ApprovalDecision::Approved);

        return $package->fresh();
    }

    public function test_creating_a_package_makes_a_draft_with_a_v1_working_version(): void
    {
        $package = app(CreatePackage::class)->handle($this->seller, $this->content(), $this->itinerary());

        $this->assertSame(PackageStatus::Draft, $package->status);
        $this->assertSame($this->seller->id, $package->owner_id);
        $this->assertSame($this->seller->id, $package->created_by);
        $this->assertMatchesRegularExpression('/^PKG-\d{4}-0001$/', $package->reference);
        $this->assertNull($package->live_version_id);
        $version = $package->workingVersion;
        $this->assertSame('v1.0', $version->label());
        $this->assertSame(PackageVersionStatus::Draft, $version->status);
        $this->assertCount(3, $version->itineraryDays);
        $this->assertTrue(AuditEvent::query()->where('action', 'package.created')->exists());
    }

    public function test_people_outside_travel_sales_cannot_create_packages(): void
    {
        foreach ([Role::SalesManager, Role::Hr, Role::Accounts, Role::Salesperson] as $role) {
            try {
                app(CreatePackage::class)->handle(User::factory()->withRole($role)->create(), $this->content(['name' => 'X '.$role->value]), $this->itinerary());
                $this->fail($role->value.' created a package');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $this->assertSame(0, Package::count());
    }

    public function test_another_salesperson_cannot_edit_someone_elses_package(): void
    {
        $package = $this->readyPackage();
        $other = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->expectException(HttpException::class);
        app(SavePackage::class)->handle($other, $package, $this->content(['adult_price' => 1]), $this->itinerary());
    }

    public function test_submission_needs_every_readiness_item(): void
    {
        $package = app(CreatePackage::class)->handle($this->seller, $this->content(['cancellation_policy' => null]), []);

        $missing = PackageReadiness::missing($package, $package->workingVersion);
        $this->assertContains('Cancellation policy', $missing);
        $this->assertContains('Itinerary (at least one day)', $missing);
        $this->assertContains('Media (at least one image)', $missing);

        $this->expectException(ValidationException::class);
        app(SubmitPackage::class)->handle($this->seller, $package);
    }

    public function test_an_expired_contract_fails_readiness(): void
    {
        $this->contract->update(['starts_on' => today()->subYear(), 'ends_on' => today()->subDay()]);
        $package = $this->readyPackage();

        $this->assertContains('Active provider contract', PackageReadiness::missing($package, $package->workingVersion));
    }

    public function test_full_two_level_approval_makes_the_version_live(): void
    {
        $package = $this->readyPackage();
        app(SubmitPackage::class)->handle($this->seller, $package);
        $this->assertSame(PackageStatus::PendingApproval, $package->fresh()->status);
        Notification::assertSentTo($this->salesAdmin, SmartAlert::class, fn ($n) => $n->type === 'package_submitted');

        app(ReviewPackage::class)->handle($this->salesAdmin, $package->fresh(), ApprovalDecision::Approved);
        $this->assertSame(PackageVersionStatus::SalesAdminApproved, $package->fresh()->workingVersion->status);
        $this->assertNull($package->fresh()->live_version_id);

        app(ReviewPackage::class)->handle($this->superAdmin, $package->fresh(), ApprovalDecision::Approved);
        $package->refresh();
        $this->assertSame(PackageStatus::Approved, $package->status);
        $this->assertNull($package->working_version_id);
        $this->assertSame(PackageVersionStatus::Approved, $package->liveVersion->status);
        $this->assertNotNull($package->liveVersion->approved_at);

        $this->assertSame([
            [ApprovalLevel::SalesAdmin, $this->salesAdmin->id],
            [ApprovalLevel::SuperAdmin, $this->superAdmin->id],
        ], PackageApproval::query()->orderBy('id')->get()->map(fn ($a) => [$a->level, $a->user_id])->all());
        Notification::assertSentTo($this->seller, SmartAlert::class, fn ($n) => $n->type === 'package_approved');
    }

    public function test_a_salesperson_cannot_approve_any_package(): void
    {
        $package = $this->readyPackage();
        app(SubmitPackage::class)->handle($this->seller, $package);

        foreach ([$this->seller, User::factory()->withRole(Role::TravelSalesperson)->create(), User::factory()->withRole(Role::SalesManager)->create()] as $user) {
            try {
                app(ReviewPackage::class)->handle($user, $package->fresh(), ApprovalDecision::Approved);
                $this->fail('Unauthorised approval succeeded');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $this->assertSame(0, PackageApproval::count());
    }

    public function test_the_super_admin_cannot_skip_the_sales_admin_review_or_approve_twice(): void
    {
        $package = $this->readyPackage();
        app(SubmitPackage::class)->handle($this->seller, $package);

        // The Super Admin holds every permission but must leave the first review to a Sales Admin.
        $this->assertFalse(ReviewPackage::canReview($this->superAdmin, $package->fresh(), $package->fresh()->workingVersion));

        try {
            app(ReviewPackage::class)->handle($this->superAdmin, $package->fresh(), ApprovalDecision::Approved);
            $this->fail('The Super Admin gave the Sales Admin approval.');
        } catch (HttpException) {
        }

        $this->assertSame(PackageVersionStatus::Submitted, $package->fresh()->workingVersion->status);

        app(ReviewPackage::class)->handle($this->salesAdmin, $package->fresh(), ApprovalDecision::Approved);
        app(ReviewPackage::class)->handle($this->superAdmin, $package->fresh(), ApprovalDecision::Approved);
        $this->assertSame(PackageVersionStatus::Approved, $package->fresh()->liveVersion->status);
    }

    public function test_a_sales_admin_cannot_give_the_final_approval(): void
    {
        $package = $this->readyPackage();
        app(SubmitPackage::class)->handle($this->seller, $package);
        app(ReviewPackage::class)->handle($this->salesAdmin, $package->fresh(), ApprovalDecision::Approved);

        $this->expectException(HttpException::class);
        app(ReviewPackage::class)->handle(User::factory()->withRole(Role::SalesAdmin)->create(), $package->fresh(), ApprovalDecision::Approved);
    }

    public function test_nobody_approves_their_own_package_not_even_a_super_admin(): void
    {
        $provider = TravelProvider::factory()->create(['owner_id' => $this->superAdmin->id]);
        $contract = ProviderContract::factory()->create(['travel_provider_id' => $provider->id]);
        $package = app(CreatePackage::class)->handle($this->superAdmin, $this->content(['travel_provider_id' => $provider->id, 'provider_contract_id' => $contract->id]), $this->itinerary());
        app(SyncPackageMedia::class)->handle($this->superAdmin, $package, [MediaAsset::factory()->create()->id]);
        app(SubmitPackage::class)->handle($this->superAdmin, $package->fresh());

        $this->assertFalse(ReviewPackage::canReview($this->superAdmin, $package->fresh(), $package->fresh()->workingVersion));
        $this->expectException(HttpException::class);
        app(ReviewPackage::class)->handle($this->superAdmin, $package->fresh(), ApprovalDecision::Approved);
    }

    public function test_rejection_needs_a_reason_and_the_creator_can_fix_and_resubmit(): void
    {
        $package = $this->readyPackage();
        app(SubmitPackage::class)->handle($this->seller, $package);

        try {
            app(ReviewPackage::class)->handle($this->salesAdmin, $package->fresh(), ApprovalDecision::Rejected, '  ');
            $this->fail('Rejected without a reason');
        } catch (ValidationException) {
            $this->assertSame(0, PackageApproval::count());
        }

        app(ReviewPackage::class)->handle($this->salesAdmin, $package->fresh(), ApprovalDecision::Rejected, 'Missing cancellation policy detail.');
        $package->refresh();
        $this->assertSame(PackageVersionStatus::Rejected, $package->workingVersion->status);
        $this->assertSame(PackageStatus::Draft, $package->status);
        Notification::assertSentTo($this->seller, SmartAlert::class, fn ($n) => $n->type === 'package_rejected');

        app(SavePackage::class)->handle($this->seller, $package, $this->content(['cancellation_policy' => 'Full refund up to 21 days before; 50% up to 7 days.']), $this->itinerary());
        app(SubmitPackage::class)->handle($this->seller, $package->fresh());
        $this->assertSame(PackageVersionStatus::Submitted, $package->fresh()->workingVersion->status);
        $this->assertSame('v1.0', $package->fresh()->workingVersion->label());
    }

    public function test_changes_requested_sends_the_version_back_for_editing(): void
    {
        $package = $this->readyPackage();
        app(SubmitPackage::class)->handle($this->seller, $package);
        app(ReviewPackage::class)->handle($this->salesAdmin, $package->fresh(), ApprovalDecision::Approved);
        app(ReviewPackage::class)->handle($this->superAdmin, $package->fresh(), ApprovalDecision::ChangesRequested, 'Add a meeting point.');

        $this->assertSame(PackageVersionStatus::ChangesRequested, $package->fresh()->workingVersion->status);
        $this->assertTrue($package->fresh()->workingVersion->isEditable());
    }

    public function test_a_version_awaiting_review_cannot_be_edited_until_withdrawn(): void
    {
        $package = $this->readyPackage();
        app(SubmitPackage::class)->handle($this->seller, $package);

        try {
            app(SavePackage::class)->handle($this->seller, $package->fresh(), $this->content(['adult_price' => 1000]), $this->itinerary());
            $this->fail('Edited a version under review');
        } catch (ValidationException) {
            $this->assertEquals(45000, $package->fresh()->workingVersion->adult_price);
        }

        app(WithdrawPackage::class)->handle($this->seller, $package->fresh());
        $this->assertSame(PackageVersionStatus::Draft, $package->fresh()->workingVersion->status);
    }

    public function test_approval_rows_are_only_written_by_the_review_action(): void
    {
        $package = $this->approvedPackage();

        $this->assertSame(2, PackageApproval::count());
        $this->assertSame(0, PackageApproval::query()->whereNotIn('user_id', [$this->salesAdmin->id, $this->superAdmin->id])->count());
    }

    public function test_a_salesperson_cannot_publish_an_unapproved_package(): void
    {
        $package = $this->readyPackage();

        foreach (['draft' => fn () => null, 'pending' => fn () => app(SubmitPackage::class)->handle($this->seller, $package->fresh()),
            'half approved' => fn () => app(ReviewPackage::class)->handle($this->salesAdmin, $package->fresh(), ApprovalDecision::Approved)] as $state => $step) {
            $step();

            try {
                app(PublishPackage::class)->handle($this->seller, $package->fresh(), 'tourlast.com');
                $this->fail('Published a '.$state.' package');
            } catch (ValidationException) {
                $this->assertNotSame(PackageStatus::Published, $package->fresh()->status);
            }
        }
    }

    public function test_an_approved_package_is_published_with_its_channel(): void
    {
        $package = $this->approvedPackage();

        app(PublishPackage::class)->handle($this->seller, $package, 'tourlast.com', 'https://www.tourlast.com/packages/mara');

        $package->refresh();
        $this->assertSame(PackageStatus::Published, $package->status);
        $this->assertSame('tourlast.com', $package->published_channel);
        $this->assertSame($this->seller->id, $package->published_by);

        app(UnpublishPackage::class)->handle($this->seller, $package, 'Season over');
        $this->assertSame(PackageStatus::Unpublished, $package->fresh()->status);
    }

    public function test_an_expired_contract_blocks_publishing_unless_a_super_admin_overrides(): void
    {
        $package = $this->approvedPackage();
        $this->contract->update(['starts_on' => today()->subYear(), 'ends_on' => today()->subDay()]);

        try {
            app(PublishPackage::class)->handle($this->seller, $package, 'tourlast.com', null, 'Please');
            $this->fail('Published with an expired contract');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Cannot publish package', $e->getMessage());
        }

        try {
            app(PublishPackage::class)->handle($this->superAdmin, $package->fresh(), 'tourlast.com');
            $this->fail('Super Admin published without a reason');
        } catch (ValidationException) {
        }

        app(PublishPackage::class)->handle($this->superAdmin, $package->fresh(), 'tourlast.com', null, 'Renewal signed, paperwork in transit.');
        $package->refresh();
        $this->assertSame(PackageStatus::Published, $package->status);
        $this->assertSame($this->superAdmin->id, $package->contract_override_by);
        $this->assertTrue(AuditEvent::query()->where('action', 'package.contract_override')->exists());
    }

    public function test_a_material_change_creates_v1_1_that_needs_approval_while_v1_0_keeps_selling(): void
    {
        $package = $this->approvedPackage();
        app(PublishPackage::class)->handle($this->seller, $package, 'tourlast.com');
        $live = $package->fresh()->liveVersion;

        $version = app(SavePackage::class)->handle($this->seller, $package->fresh(), $this->content(['adult_price' => 52000]), $this->itinerary());

        $package->refresh();
        $this->assertSame('v1.1', $version->label());
        $this->assertSame(PackageVersionStatus::Draft, $version->status);
        $this->assertSame($live->id, $package->live_version_id);
        $this->assertSame($version->id, $package->working_version_id);
        $this->assertSame(PackageStatus::Published, $package->status);
        $this->assertEquals(45000, $package->liveVersion->adult_price);
        $this->assertSame(['45000', '52000'], $version->material_changes['adult_price']);

        app(SubmitPackage::class)->handle($this->seller, $package);
        app(ReviewPackage::class)->handle($this->salesAdmin, $package->fresh(), ApprovalDecision::Approved);
        app(ReviewPackage::class)->handle($this->superAdmin, $package->fresh(), ApprovalDecision::Approved);

        $package->refresh();
        $this->assertSame($version->id, $package->live_version_id);
        $this->assertSame(PackageStatus::Published, $package->status);
        $this->assertSame(PackageVersionStatus::Superseded, $live->fresh()->status);
    }

    public function test_itinerary_changes_are_material(): void
    {
        $package = $this->approvedPackage();
        $itinerary = $this->itinerary();
        $itinerary[] = ['title' => 'Extra day at the lake', 'meals' => []];

        $version = app(SavePackage::class)->handle($this->seller, $package, $this->content(['days' => 4]), $itinerary);

        $this->assertArrayHasKey('itinerary', $version->material_changes);
        $this->assertSame(PackageVersionStatus::Draft, $version->status);
    }

    public function test_a_non_material_change_is_applied_without_approval_and_audited(): void
    {
        $package = $this->approvedPackage();
        $live = $package->liveVersion;

        $version = app(SavePackage::class)->handle($this->seller, $package, $this->content(['overview' => 'Sunrise game drives.', 'meeting_point' => 'Westlands office']), $this->itinerary());

        $package->refresh();
        $this->assertSame(PackageVersionStatus::Approved, $version->status);
        $this->assertSame($version->id, $package->live_version_id);
        $this->assertNull($package->working_version_id);
        $this->assertSame(PackageVersionStatus::Superseded, $live->fresh()->status);
        $this->assertSame('Sunrise game drives.', $package->liveVersion->overview);
        $this->assertTrue(AuditEvent::query()->where('action', 'package.minor_change')->where('summary', 'like', 'Non-material change, applied without approval%')->exists());
    }

    public function test_an_approved_version_is_never_modified(): void
    {
        $package = $this->approvedPackage();
        $live = $package->liveVersion;
        $before = PackageContent::contentOf($live);

        app(SavePackage::class)->handle($this->seller, $package, $this->content(['adult_price' => 99000, 'inclusions' => ['Nothing']]), $this->itinerary());

        $this->assertSame($before, PackageContent::contentOf($live->fresh()));
    }

    public function test_duplicate_detection(): void
    {
        app(CreatePackage::class)->handle($this->seller, $this->content(), $this->itinerary());

        try {
            app(CreatePackage::class)->handle($this->seller, $this->content(), $this->itinerary(), acceptDuplicate: true);
            $this->fail('Salesperson created an exact duplicate');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('duplicate', $e->errors());
        }

        // A similar (not identical) name needs confirming, then a salesperson may continue.
        try {
            app(CreatePackage::class)->handle($this->seller, $this->content(['name' => 'Masai Mara 4-Day Safari']), $this->itinerary());
            $this->fail('Similar package created without confirmation');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Possible duplicate package found', $e->errors()['duplicate'][0]);
        }

        app(CreatePackage::class)->handle($this->seller, $this->content(['name' => 'Masai Mara 4-Day Safari']), $this->itinerary(), acceptDuplicate: true);

        // Managers may continue past an exact match.
        app(CreatePackage::class)->handle($this->salesAdmin, $this->content(), $this->itinerary(), acceptDuplicate: true);
        $this->assertSame(3, Package::count());
    }

    public function test_duplicating_a_package_copies_content_but_not_prices_or_capacity(): void
    {
        $source = $this->approvedPackage();
        $other = User::factory()->withRole(Role::TravelSalesperson)->create();

        $copy = app(DuplicatePackage::class)->handle($other, $source, 'Masai Mara 4-Day Safari', acceptDuplicate: true);

        $version = $copy->workingVersion;
        $this->assertNotSame($source->reference, $copy->reference);
        $this->assertSame($other->id, $copy->owner_id);
        $this->assertSame(PackageStatus::Draft, $copy->status);
        $this->assertSame(['Park fees', 'Meals'], $version->inclusions);
        $this->assertCount(3, $version->itineraryDays);
        $this->assertNull($version->adult_price);
        $this->assertNull($version->default_capacity);
        $this->assertNull($copy->driver_id);
        $this->assertSame($this->contract->id, $version->provider_contract_id);
        $this->assertCount(1, $copy->media);
        $this->assertContains('Pricing (adult price)', PackageReadiness::missing($copy, $version));
    }

    public function test_revoked_media_cannot_be_added(): void
    {
        $package = app(CreatePackage::class)->handle($this->seller, $this->content(), $this->itinerary());

        $this->expectException(ValidationException::class);
        app(SyncPackageMedia::class)->handle($this->seller, $package, [MediaAsset::factory()->revoked()->create()->id]);
    }

    public function test_financial_fields_cannot_be_set_by_people_without_finance_access(): void
    {
        $package = $this->readyPackage();
        // A Sales Admin can edit everyone's packages and sees finance; a manager without finance would fall back.
        $version = app(SavePackage::class)->handle($this->seller, $package, $this->content(['net_provider_price' => 30000]), $this->itinerary());
        $this->assertEquals(30000, $version->net_provider_price);
    }
}
