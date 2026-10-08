<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A package is the product; its content lives in numbered versions.
     * live_version_id is the approved version customers see; working_version_id
     * is the draft or pending version being edited or reviewed (null when the
     * live version is the latest). Name, provider, destination and type are
     * copied onto the package from the working (or live) version for listing.
     */
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('name');
            $table->string('name_key')->index();
            $table->string('package_type', 30);
            $table->string('destination', 120)->index();
            $table->string('country', 60)->default('Kenya');
            $table->foreignId('travel_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedBigInteger('live_version_id')->nullable();
            $table->unsignedBigInteger('working_version_id')->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guide_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('guide_required')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('published_channel')->nullable();
            $table->string('published_url')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->foreignId('contract_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('contract_override_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['owner_id', 'status']);
            $table->index(['travel_provider_id', 'status']);
        });

        Schema::create('package_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('major')->default(1);
            $table->unsignedSmallInteger('minor')->default(0);
            $table->string('status', 30)->default('draft')->index();

            $table->string('name');
            $table->string('short_description', 300)->nullable();
            $table->text('description')->nullable();
            $table->string('package_type', 30);
            $table->foreignId('travel_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('destination', 120);
            $table->string('country', 60)->default('Kenya');
            $table->string('region', 80)->nullable();
            $table->string('start_location')->nullable();
            $table->string('end_location')->nullable();
            $table->string('duration_label', 60)->nullable();
            $table->unsignedSmallInteger('days')->default(1);
            $table->unsignedSmallInteger('nights')->default(0);
            $table->string('difficulty', 20)->nullable();
            $table->unsignedSmallInteger('min_travelers')->default(1);
            $table->unsignedSmallInteger('max_travelers')->nullable();
            $table->unsignedSmallInteger('default_capacity')->nullable();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->string('age_notes')->nullable();

            $table->text('overview')->nullable();
            $table->json('highlights')->nullable();
            $table->json('inclusions')->nullable();
            $table->json('exclusions')->nullable();
            $table->text('requirements')->nullable();
            $table->json('what_to_bring')->nullable();
            $table->text('terms')->nullable();
            $table->text('cancellation_policy')->nullable();
            $table->text('refund_policy')->nullable();
            $table->string('meeting_point')->nullable();
            $table->text('pickup_info')->nullable();
            $table->text('dropoff_info')->nullable();

            $table->string('currency', 3)->default('KES');
            $table->decimal('adult_price', 12, 2)->nullable();
            $table->decimal('child_price', 12, 2)->nullable();
            $table->decimal('infant_price', 12, 2)->nullable();
            $table->decimal('group_price', 12, 2)->nullable();
            $table->unsignedSmallInteger('group_min_size')->nullable();
            $table->decimal('single_supplement', 12, 2)->nullable();
            $table->decimal('provider_price', 12, 2)->nullable();
            $table->decimal('net_provider_price', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->nullable();
            $table->decimal('commission_amount', 12, 2)->nullable();

            $table->json('material_changes')->nullable();
            $table->text('change_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['package_id', 'major', 'minor']);
        });

        Schema::create('package_itinerary_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('day_number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('activities')->nullable();
            $table->json('meals')->nullable();
            $table->string('accommodation')->nullable();
            $table->string('transport')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['package_version_id', 'day_number']);
        });

        Schema::create('package_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_version_id')->constrained()->cascadeOnDelete();
            $table->string('level', 20);
            $table->string('decision', 20);
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['package_version_id', 'level']);
        });

        Schema::create('package_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['package_id', 'media_asset_id']);
        });

        Schema::create('package_departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->time('start_time')->nullable();
            $table->date('ends_on');
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('waitlist_count')->default(0);
            $table->string('status', 20)->default('open');
            $table->string('trip_status', 20)->default('scheduled');
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guide_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('allow_overbooking')->default(false);
            $table->foreignId('overbooking_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['package_id', 'starts_on']);
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_departures');
        Schema::dropIfExists('package_media');
        Schema::dropIfExists('package_approvals');
        Schema::dropIfExists('package_itinerary_days');
        Schema::dropIfExists('package_versions');
        Schema::dropIfExists('packages');
    }
};
