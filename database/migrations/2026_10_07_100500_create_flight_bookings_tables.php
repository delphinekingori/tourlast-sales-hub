<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Read-only copy of bookings from Tourlast Flights Super Admin (the source
     * of truth). Statuses are stored exactly as the Flights system sends them.
     * Rows are only ever written by the sync.
     */
    public function up(): void
    {
        Schema::create('flight_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 30)->default('tourlast-flights');
            $table->string('external_id', 80);
            $table->string('booking_reference', 40)->nullable()->index();
            $table->string('pnr', 20)->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('airline_code', 5)->nullable()->index();
            $table->string('airline_name')->nullable();
            $table->string('origin', 5)->nullable();
            $table->string('destination', 5)->nullable();
            $table->string('trip_type', 20)->nullable();
            $table->string('cabin', 20)->nullable();
            $table->timestamp('departure_at')->nullable()->index();
            $table->timestamp('arrival_at')->nullable();
            $table->timestamp('return_at')->nullable();
            $table->unsignedSmallInteger('passenger_count')->default(1);
            $table->string('currency', 3)->default('KES');
            $table->decimal('fare_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->decimal('markup_amount', 12, 2)->nullable();
            $table->string('booking_status', 30)->nullable()->index();
            $table->string('payment_status', 30)->nullable();
            $table->string('cancellation_status', 30)->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('refund_status', 30)->nullable()->index();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('refund_method', 30)->nullable();
            $table->timestamp('refund_requested_at')->nullable();
            $table->timestamp('refund_completed_at')->nullable();
            $table->timestamp('booked_at')->nullable()->index();
            $table->string('agent_reference', 60)->nullable();
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('promo_code', 30)->nullable();
            $table->foreignId('influencer_code_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 20)->default('synced');
            $table->string('sync_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['source_system', 'external_id']);
            $table->index(['salesperson_id', 'booked_at']);
        });

        Schema::create('flight_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence')->default(1);
            $table->string('flight_number', 10)->nullable();
            $table->string('airline_code', 5)->nullable();
            $table->string('origin', 5)->nullable();
            $table->string('destination', 5)->nullable();
            $table->timestamp('departure_at')->nullable();
            $table->timestamp('arrival_at')->nullable();
            $table->string('cabin', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('flight_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('passenger_type', 10)->default('adult');
            $table->string('ticket_number', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_passengers');
        Schema::dropIfExists('flight_segments');
        Schema::dropIfExists('flight_bookings');
    }
};
