<?php

namespace Tests\Feature\Travel\Providers;

use App\Enums\Role;
use App\Livewire\Travel\Contracts\Index as ContractIndex;
use App\Livewire\Travel\Contracts\Show as ContractShow;
use App\Livewire\Travel\Providers\Show as ProviderShow;
use App\Models\AuditEvent;
use App\Models\ContractDocument;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ContractDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private const Docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_new_contract_can_be_created_with_its_signed_document(): void
    {
        $provider = TravelProvider::factory()->create();

        Livewire::actingAs($provider->owner)->test(ProviderShow::class, ['provider' => $provider->id])
            ->call('openContract', $provider->id)
            ->assertSet('contractCanAttach', true)
            ->set('contractFile', UploadedFile::fake()->create('signed-agreement.pdf', 300, 'application/pdf'))
            ->call('saveContract')
            ->assertHasNoErrors();

        $document = ProviderContract::query()->firstOrFail()->documents()->firstOrFail();
        $this->assertSame('signed_contract', $document->type);
        $this->assertSame('signed-agreement.pdf', $document->original_name);
        $this->assertSame('application/pdf', $document->mime_type);
        Storage::disk('local')->assertExists($document->path);
        $this->assertStringStartsWith('travel/contracts/'.$document->provider_contract_id.'/', $document->path);
        $this->assertTrue(AuditEvent::query()->where('action', 'contract.document_added')->where('subject_id', $document->provider_contract_id)->exists());
    }

    public function test_editing_a_contract_with_a_new_file_replaces_the_signed_document_and_keeps_the_old_one(): void
    {
        $contract = ProviderContract::factory()->draft()->create();
        $owner = $contract->provider->owner;

        $component = Livewire::actingAs($owner)->test(ContractShow::class, ['contract' => $contract->id])
            ->call('openContract', $contract->travel_provider_id, $contract->id)
            ->set('contractFile', UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf'))
            ->call('saveContract')
            ->assertHasNoErrors()
            ->call('openContract', $contract->travel_provider_id, $contract->id)
            ->assertSet('contractCurrentFile', 'v1.pdf (100.0 KB)')
            ->set('contractFile', UploadedFile::fake()->create('v2.docx', 120, self::Docx))
            ->call('saveContract')
            ->assertHasNoErrors();

        [$old, $new] = $contract->documents()->orderBy('id')->get()->all();
        $this->assertSame($new->id, $old->replaced_by_id);
        $this->assertTrue($new->isCurrent());
        $this->assertSame(['v2.docx'], $contract->currentDocuments()->pluck('original_name')->all());
        Storage::disk('local')->assertExists([$old->path, $new->path]);
        $this->assertTrue(AuditEvent::query()->where('action', 'contract.document_replaced')->where('subject_id', $contract->id)->exists());

        $component->assertSee('v2.docx')->assertSee('Earlier versions and removed files (1)');
    }

    public function test_pdf_doc_and_docx_files_are_accepted_on_the_contract_page(): void
    {
        $contract = ProviderContract::factory()->create();

        foreach ([['terms.pdf', 'application/pdf'], ['rates.doc', 'application/msword'], ['addendum.docx', self::Docx]] as [$name, $mime]) {
            Livewire::actingAs($contract->provider->owner)->test(ContractShow::class, ['contract' => $contract->id])
                ->set('documentType', 'terms')
                ->set('document', UploadedFile::fake()->create($name, 50, $mime))
                ->call('uploadDocument')
                ->assertHasNoErrors();
        }

        $this->assertSame(3, $contract->currentDocuments()->count());
    }

    public function test_other_file_types_and_oversized_files_are_rejected(): void
    {
        $contract = ProviderContract::factory()->create();

        foreach ([
            UploadedFile::fake()->create('photo.png', 50, 'image/png'),
            UploadedFile::fake()->create('notes.txt', 5, 'text/plain'),
            UploadedFile::fake()->create('disguised.pdf', 50, 'image/png'),
            UploadedFile::fake()->create('script.php', 5, 'application/pdf'),
            UploadedFile::fake()->create('huge.pdf', ContractDocument::MaxKilobytes + 1, 'application/pdf'),
        ] as $file) {
            Livewire::actingAs($contract->provider->owner)->test(ContractShow::class, ['contract' => $contract->id])
                ->set('document', $file)
                ->call('uploadDocument')
                ->assertHasErrors('document');
        }

        $provider = $contract->provider;
        Livewire::actingAs($provider->owner)->test(ProviderShow::class, ['provider' => $provider->id])
            ->call('openContract', $provider->id)
            ->set('contractFile', UploadedFile::fake()->create('sheet.xlsx', 50, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))
            ->call('saveContract')
            ->assertHasErrors('contractFile');

        $this->assertSame(0, ContractDocument::query()->count());
        $this->assertSame(1, ProviderContract::query()->count());
    }

    public function test_replace_and_remove_keep_history_and_are_audited(): void
    {
        $contract = ProviderContract::factory()->create();

        $component = Livewire::actingAs($contract->provider->owner)->test(ContractShow::class, ['contract' => $contract->id])
            ->set('documentType', 'rate_sheet')
            ->set('document', UploadedFile::fake()->create('rates-2026.pdf', 80, 'application/pdf'))
            ->call('uploadDocument');
        $first = $contract->documents()->firstOrFail();

        $component->call('startReplacing', $first->id)
            ->assertSet('documentType', 'rate_sheet')
            ->set('document', UploadedFile::fake()->create('rates-2026-v2.pdf', 80, 'application/pdf'))
            ->call('uploadDocument')
            ->assertHasNoErrors();
        $second = $contract->documents()->latest('id')->firstOrFail();
        $this->assertSame($second->id, $first->fresh()->replaced_by_id);

        $component->call('removeDocument', $second->id);
        $this->assertNotNull($second->fresh()->removed_at);
        $this->assertSame(0, $contract->currentDocuments()->count());
        $this->assertSame(2, $contract->documents()->count());
        Storage::disk('local')->assertExists([$first->path, $second->path]);

        $this->assertSame(
            ['contract.document_added', 'contract.document_replaced', 'contract.document_removed'],
            AuditEvent::query()->where('subject_id', $contract->id)->where('action', 'like', 'contract.document_%')->orderBy('id')->pluck('action')->all(),
        );

        $this->actingAs($contract->provider->owner)->get(route('travel.contracts.document', $first->id))->assertOk();
    }

    public function test_people_who_cannot_change_the_provider_cannot_upload_or_remove(): void
    {
        $contract = ProviderContract::factory()->create();
        $document = $contract->documents()->create(['type' => 'signed_contract', 'path' => 'travel/contracts/'.$contract->id.'/a.pdf', 'original_name' => 'a.pdf', 'size' => 10]);
        $stranger = User::factory()->withRole(Role::TravelSalesperson)->create();

        Livewire::actingAs($stranger)->test(ContractShow::class, ['contract' => $contract->id])
            ->assertDontSee('a.pdf')
            ->set('document', UploadedFile::fake()->create('mine.pdf', 10, 'application/pdf'))
            ->call('uploadDocument')
            ->assertForbidden();

        Livewire::actingAs($stranger)->test(ContractShow::class, ['contract' => $contract->id])
            ->call('removeDocument', $document->id)
            ->assertForbidden();

        $this->assertTrue($document->fresh()->isCurrent());
        $this->assertSame(1, $contract->documents()->count());
    }

    public function test_only_people_who_may_see_the_contract_terms_can_download(): void
    {
        $contract = ProviderContract::factory()->create();
        $path = UploadedFile::fake()->create('signed.pdf', 20, 'application/pdf')->store('travel/contracts/'.$contract->id, 'local');
        $document = $contract->documents()->create(['type' => 'signed_contract', 'path' => $path, 'original_name' => 'signed.pdf', 'size' => 20480]);
        $url = route('travel.contracts.document', $document->id);

        $this->actingAs($contract->provider->owner)->get($url)->assertOk();
        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->get($url)->assertOk();
        $this->actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::SalesManager)->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get($url)->assertForbidden();
    }

    public function test_guests_are_sent_to_log_in_before_downloading(): void
    {
        $contract = ProviderContract::factory()->create();
        $document = $contract->documents()->create(['type' => 'signed_contract', 'path' => 'travel/contracts/'.$contract->id.'/a.pdf', 'original_name' => 'a.pdf', 'size' => 10]);

        $this->get(route('travel.contracts.document', $document->id))->assertRedirect();
    }

    public function test_the_contracts_list_links_the_signed_document_with_name_and_size(): void
    {
        $contract = ProviderContract::factory()->create();
        $document = $contract->documents()->create(['type' => 'signed_contract', 'path' => 'travel/contracts/'.$contract->id.'/x.pdf', 'original_name' => 'kenya-safaris-signed.pdf', 'size' => 2 * 1024 * 1024]);

        Livewire::actingAs($contract->provider->owner)->test(ContractIndex::class)
            ->assertSee('kenya-safaris-signed.pdf')
            ->assertSee('2.0 MB')
            ->assertSee(route('travel.contracts.document', $document->id));

        Livewire::actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())->test(ContractIndex::class)
            ->assertDontSee('kenya-safaris-signed.pdf')
            ->assertSee('Restricted');
    }
}
