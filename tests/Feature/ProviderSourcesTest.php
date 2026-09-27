<?php

namespace Tests\Feature;

use App\Enums\OnboardingStatus;
use App\Integrations\Tourlast\ApiProviderSource;
use App\Integrations\Tourlast\DatabaseProviderSource;
use App\Integrations\Tourlast\ProviderRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProviderSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_source_reads_referred_rows_using_the_column_map(): void
    {
        config([
            'database.connections.tourlast' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'tourlast.database.table' => 'hosts',
            'tourlast.database.columns.property_id' => 'host_id',
            'tourlast.database.columns.property_name' => 'listing_title',
            'tourlast.database.columns.location' => 'town',
            'tourlast.database.columns.rejected_at' => null,
        ]);
        DB::purge('tourlast');

        Schema::connection('tourlast')->create('hosts', function (Blueprint $table) {
            $table->id('host_id');
            $table->string('ref_code')->nullable();
            $table->string('listing_title');
            $table->string('property_type');
            $table->string('town')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::connection('tourlast')->table('hosts')->insert([
            ['ref_code' => 'TL-JOHN-2847', 'listing_title' => 'Coral Bay Villas', 'property_type' => 'Guest House', 'town' => 'Diani', 'status' => 'Published', 'created_at' => '2026-09-01 10:00', 'approved_at' => '2026-09-03 10:00', 'published_at' => '2026-09-04 10:00', 'updated_at' => '2026-09-04 10:00'],
            ['ref_code' => null, 'listing_title' => 'Walk-in Hotel', 'property_type' => 'hotel', 'town' => 'Nairobi', 'status' => 'pending', 'created_at' => '2026-09-02 10:00', 'approved_at' => null, 'published_at' => null, 'updated_at' => '2026-09-02 10:00'],
        ]);

        /** @var list<ProviderRecord> $records */
        $records = iterator_to_array(app(DatabaseProviderSource::class)->changedSince(null), false);

        $this->assertCount(1, $records);
        $this->assertSame('1', (string) $records[0]->propertyId);
        $this->assertSame('Coral Bay Villas', $records[0]->propertyName);
        $this->assertSame('guesthouse', $records[0]->propertyType);
        $this->assertSame(OnboardingStatus::Active, $records[0]->status);
        $this->assertSame('Diani', $records[0]->location);
        $this->assertSame('2026-09-03', $records[0]->approvedAt->toDateString());

        $this->assertCount(0, iterator_to_array(app(DatabaseProviderSource::class)->changedSince(CarbonImmutable::parse('2026-09-05')), false));
    }

    public function test_the_api_source_follows_pages_and_sends_the_token(): void
    {
        config(['tourlast.api.base_url' => 'https://www.tourlast.com', 'tourlast.api.token' => 'secret-token']);

        Http::fake([
            'www.tourlast.com/api/sales-hub/referrals?*page=1*' => Http::response([
                'data' => [['property_id' => 'TL-1', 'ref_code' => 'TL-MARY-1111', 'property_name' => 'Amani Apartments', 'property_type' => 'apartment', 'status' => 'approved', 'approved_at' => '2026-09-10T09:00:00+03:00']],
                'next_page' => 2,
            ]),
            'www.tourlast.com/api/sales-hub/referrals?*page=2*' => Http::response([
                'data' => [['property_id' => 'TL-2', 'ref_code' => 'TL-MARY-1111', 'property_name' => 'Tsavo Lodge', 'property_type' => 'safari', 'status' => 'in_review']],
                'next_page' => null,
            ]),
        ]);

        $records = iterator_to_array(app(ApiProviderSource::class)->changedSince(CarbonImmutable::parse('2026-09-01')), false);

        $this->assertCount(2, $records);
        $this->assertSame('tour', $records[1]->propertyType);
        $this->assertSame(OnboardingStatus::UnderReview, $records[1]->status);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token')
            && str_contains(urldecode($request->url()), 'updated_since=2026-09-01'));
    }
}
