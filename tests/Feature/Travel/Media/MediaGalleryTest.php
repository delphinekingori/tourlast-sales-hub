<?php

namespace Tests\Feature\Travel\Media;

use App\Actions\Travel\Media\StoreMediaAsset;
use App\Enums\Role;
use App\Enums\Travel\MediaCategory;
use App\Enums\Travel\MediaUsagePermission;
use App\Livewire\Travel\Media\Index;
use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\Package;
use App\Models\User;
use App\Support\Travel\MediaRules;
use Database\Seeders\Travel\MediaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @return array<string, array{Role, int}>
     */
    public static function access(): array
    {
        return [
            'travel salesperson' => [Role::TravelSalesperson, 200],
            'sales admin' => [Role::SalesAdmin, 200],
            'super admin' => [Role::SuperAdmin, 200],
            'sales manager' => [Role::SalesManager, 403],
            'hr' => [Role::Hr, 403],
            'accounts' => [Role::Accounts, 403],
            'salesperson' => [Role::Salesperson, 403],
        ];
    }

    #[DataProvider('access')]
    public function test_only_travel_sales_can_open_the_gallery(Role $role, int $status): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('travel.media.index'))
            ->assertStatus($status);
    }

    public function test_a_file_can_be_removed_from_the_upload_list_before_uploading(): void
    {
        Livewire::actingAs($this->travelSalesperson())->test(Index::class)
            ->call('openUpload')
            ->set('uploads', [
                UploadedFile::fake()->image('first.jpg', 300, 200),
                UploadedFile::fake()->image('second.jpg', 300, 200),
            ])
            ->call('discardUpload', 0)
            ->assertHasNoErrors()
            ->assertCount('uploads', 1)
            ->assertSet('titles', ['Second']);
    }

    public function test_a_batch_upload_stores_every_file_with_the_shared_details(): void
    {
        $user = $this->travelSalesperson();

        Livewire::actingAs($user)->test(Index::class)
            ->call('openUpload')
            ->set('uploads', [
                UploadedFile::fake()->image('lion_pride-at-dawn.jpg', 640, 480),
                UploadedFile::fake()->image('lodge-deck.png', 300, 200),
            ])
            ->assertSet('titles.0', 'Lion Pride At Dawn')
            ->set('titles.1', 'Lodge deck at sunset')
            ->set('meta.destination', 'Masai Mara')
            ->set('meta.category', MediaCategory::Wildlife->value)
            ->set('meta.tags', 'Lions, Sunrise, lions')
            ->set('meta.copyright_owner', 'Savannah Tours')
            ->call('addToGallery')
            ->assertHasNoErrors()
            ->assertSet('showUpload', false);

        $this->assertSame(2, MediaAsset::query()->count());

        $first = MediaAsset::query()->where('title', 'Lion Pride At Dawn')->firstOrFail();
        $this->assertSame(640, $first->width);
        $this->assertSame(480, $first->height);
        $this->assertSame('Lion Pride At Dawn', $first->alt_text);
        $this->assertSame(['lions', 'sunrise'], $first->tags);
        $this->assertSame('Masai Mara', $first->destination);
        $this->assertSame($user->id, $first->uploaded_by);
        $this->assertStringStartsWith('media/'.now()->format('Y/m').'/', $first->path);
        Storage::disk('public')->assertExists($first->path);

        $this->assertTrue(MediaAsset::query()->where('title', 'Lodge deck at sunset')->exists());
        $this->assertSame(2, AuditEvent::query()->where('action', 'media.uploaded')->count());
    }

    public function test_oversized_and_unsupported_files_are_refused(): void
    {
        $user = $this->travelSalesperson();

        Livewire::actingAs($user)->test(Index::class)
            ->set('uploads', [UploadedFile::fake()->image('huge.jpg')->size(9000)])
            ->call('addToGallery')
            ->assertHasErrors('uploads.0');

        Livewire::actingAs($user)->test(Index::class)
            ->set('uploads', [UploadedFile::fake()->create('notes.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])
            ->call('addToGallery')
            ->assertHasErrors('uploads.0');

        $this->assertSame(0, MediaAsset::query()->count());
    }

    public function test_the_action_refuses_a_file_type_outside_the_gallery(): void
    {
        $this->expectException(ValidationException::class);

        app(StoreMediaAsset::class)->handle(UploadedFile::fake()->create('script.exe', 5, 'application/x-msdownload'), [], $this->travelSalesperson());
    }

    public function test_salespeople_edit_only_their_own_uploads_and_managers_edit_any(): void
    {
        $owner = $this->travelSalesperson();
        $colleague = $this->travelSalesperson();
        $asset = MediaAsset::factory()->create(['uploaded_by' => $owner->id, 'title' => 'Original']);

        Livewire::actingAs($colleague)->test(Index::class)
            ->call('open', $asset->id)
            ->set('form.title', 'Hijacked')
            ->call('saveDetail')
            ->assertForbidden();

        Livewire::actingAs($owner)->test(Index::class)
            ->call('open', $asset->id)
            ->set('form.title', 'Better title')
            ->call('saveDetail')
            ->assertHasNoErrors();

        $this->assertSame('Better title', $asset->refresh()->title);

        Livewire::actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->test(Index::class)
            ->call('open', $asset->id)
            ->set('form.destination', 'Amboseli')
            ->call('saveDetail')
            ->assertHasNoErrors();

        $this->assertSame('Amboseli', $asset->refresh()->destination);
        $this->assertTrue(AuditEvent::query()->where('action', 'media.updated')->where('subject_id', $asset->id)->exists());
    }

    public function test_only_managers_can_revoke_permission_and_revoked_media_stays_on_packages(): void
    {
        $owner = $this->travelSalesperson();
        $asset = MediaAsset::factory()->create(['uploaded_by' => $owner->id]);
        $package = Package::factory()->published()->create();
        $package->media()->attach($asset->id, ['position' => 0, 'is_primary' => true]);

        Livewire::actingAs($owner)->test(Index::class)
            ->call('open', $asset->id)
            ->set('form.usage_permission', MediaUsagePermission::Revoked->value)
            ->call('saveDetail')
            ->assertForbidden();

        Livewire::actingAs($owner)->test(Index::class)
            ->set('selected', [$asset->id])
            ->call('bulkRevoke')
            ->assertForbidden();

        Livewire::actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->test(Index::class)
            ->set('selected', [$asset->id])
            ->call('bulkRevoke');

        $asset->refresh();
        $this->assertSame(MediaUsagePermission::Revoked, $asset->usage_permission);
        $this->assertFalse(MediaAsset::query()->usable()->whereKey($asset->id)->exists());
        $this->assertTrue($package->media()->whereKey($asset->id)->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'media.permission_revoked')->exists());
    }

    public function test_media_used_on_a_package_cannot_be_deleted_but_unused_media_can(): void
    {
        $owner = $this->travelSalesperson();
        $used = MediaAsset::factory()->create(['uploaded_by' => $owner->id]);
        Package::factory()->create()->media()->attach($used->id);
        $unused = MediaAsset::factory()->create(['uploaded_by' => $owner->id]);

        Livewire::actingAs($owner)->test(Index::class)
            ->call('confirmDelete', $used->id)
            ->assertForbidden();

        Livewire::actingAs($owner)->test(Index::class)
            ->call('confirmDelete', $unused->id)
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
        Storage::disk('public')->assertMissing($unused->path);
        $this->assertTrue(AuditEvent::query()->where('action', 'media.deleted')->exists());
    }

    public function test_archiving_media_on_a_published_package_needs_a_manager_and_a_typed_confirmation(): void
    {
        $owner = $this->travelSalesperson();
        $asset = MediaAsset::factory()->create(['uploaded_by' => $owner->id]);
        Package::factory()->published()->create()->media()->attach($asset->id);

        // The uploader can open the dialog but the file is not archived.
        Livewire::actingAs($owner)->test(Index::class)
            ->call('archiveOne', $asset->id)
            ->call('archive');
        $this->assertNull($asset->refresh()->archived_at);

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        Livewire::actingAs($admin)->test(Index::class)
            ->call('archiveOne', $asset->id)
            ->set('archiveConfirmation', 'yes')
            ->call('archive')
            ->assertHasErrors('archiveConfirmation');
        $this->assertNull($asset->refresh()->archived_at);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('archiveOne', $asset->id)
            ->set('archiveConfirmation', MediaRules::ArchiveConfirmation)
            ->call('archive')
            ->assertHasNoErrors();

        $this->assertNotNull($asset->refresh()->archived_at);
        $this->assertFalse(MediaAsset::query()->usable()->whereKey($asset->id)->exists());
    }

    public function test_unused_media_can_be_archived_and_restored_by_its_uploader(): void
    {
        $owner = $this->travelSalesperson();
        $asset = MediaAsset::factory()->create(['uploaded_by' => $owner->id]);

        Livewire::actingAs($owner)->test(Index::class)
            ->call('archiveOne', $asset->id)
            ->call('archive');
        $this->assertNotNull($asset->refresh()->archived_at);

        Livewire::actingAs($owner)->test(Index::class)
            ->call('restore', $asset->id);
        $this->assertNull($asset->refresh()->archived_at);
    }

    public function test_bulk_changes_skip_files_uploaded_by_someone_else(): void
    {
        $owner = $this->travelSalesperson();
        $mine = MediaAsset::factory()->create(['uploaded_by' => $owner->id, 'category' => MediaCategory::Other, 'tags' => ['safari']]);
        $theirs = MediaAsset::factory()->create(['uploaded_by' => $this->travelSalesperson()->id, 'category' => MediaCategory::Other]);

        Livewire::actingAs($owner)->test(Index::class)
            ->set('selected', [$mine->id, $theirs->id])
            ->set('bulk.category', MediaCategory::Vehicles->value)
            ->set('bulk.tag', 'Fleet')
            ->call('bulkApply');

        $this->assertSame(MediaCategory::Vehicles, $mine->refresh()->category);
        $this->assertSame(['safari', 'fleet'], $mine->tags);
        $this->assertSame(MediaCategory::Other, $theirs->refresh()->category);
    }

    public function test_search_and_filters_narrow_the_gallery_and_show_usage(): void
    {
        $user = $this->travelSalesperson();
        $lion = MediaAsset::factory()->create(['title' => 'Lion pride', 'destination' => 'Masai Mara', 'category' => MediaCategory::Wildlife]);
        MediaAsset::factory()->create(['title' => 'Beach dhow', 'destination' => 'Diani', 'category' => MediaCategory::Activities]);
        MediaAsset::factory()->create(['title' => 'Old lodge', 'archived_at' => now()]);
        Package::factory()->create()->media()->attach($lion->id);

        Livewire::actingAs($user)->test(Index::class)
            ->assertSee('Lion pride')
            ->assertSee('Beach dhow')
            ->assertDontSee('Old lodge')
            ->assertSee('Used in 1 package')
            ->set('search', 'lion')
            ->assertSee('Lion pride')
            ->assertDontSee('Beach dhow')
            ->set('search', '')
            ->set('destination', 'Diani')
            ->assertSee('Beach dhow')
            ->assertDontSee('Lion pride')
            ->set('destination', '')
            ->set('archived', true)
            ->assertSee('Old lodge')
            ->assertDontSee('Beach dhow');
    }

    public function test_the_demo_seeder_runs_without_other_travel_data(): void
    {
        $this->seed(MediaSeeder::class);

        $this->assertSame(16, MediaAsset::query()->count());
        Storage::disk('public')->assertExists(MediaAsset::query()->firstOrFail()->path);
    }

    private function travelSalesperson(): User
    {
        return User::factory()->withRole(Role::TravelSalesperson)->create();
    }
}
