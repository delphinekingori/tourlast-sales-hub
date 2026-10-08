<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('platform', 30)->nullable();
            $table->string('handle')->nullable();
            $table->string('payout_method', 10)->nullable();
            $table->text('payout_details')->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('influencer_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->string('commission_type', 20);
            $table->decimal('commission_value', 12, 2);
            $table->string('applies_to', 20)->default('packages');
            $table->unsignedInteger('max_bookings')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'starts_on', 'ends_on']);
        });

        Schema::create('travel_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->string('phone_key', 20)->nullable()->index();
            $table->string('country', 60)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('package_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_departure_id')->constrained()->restrictOnDelete();
            $table->foreignId('travel_client_id')->constrained()->restrictOnDelete();
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('influencer_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10)->default('manual');
            $table->string('external_id')->nullable();
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);
            $table->unsignedSmallInteger('infants')->default(0);
            $table->unsignedSmallInteger('travelers')->default(1);
            $table->string('currency', 3)->default('KES');
            $table->decimal('amount_total', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_refunded', 12, 2)->default(0);
            $table->string('payment_status', 20)->default('unpaid')->index();
            $table->string('status', 20)->default('pending')->index();
            $table->text('special_requirements')->nullable();
            $table->text('dietary_requirements')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guide_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['package_departure_id', 'status']);
            $table->index(['salesperson_id', 'created_at']);
            $table->unique(['source', 'external_id']);
        });

        Schema::create('booking_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_booking_id')->constrained()->cascadeOnDelete();
            $table->string('item', 40);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['package_booking_id', 'item']);
        });

        Schema::create('package_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_booking_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->text('policy_snapshot')->nullable();
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('travel_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10);
            $table->string('channel', 10);
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('mpesa_receipt', 30)->nullable()->unique();
            $table->string('checkout_request_id', 80)->nullable()->unique();
            $table->string('merchant_request_id', 80)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('payer_name')->nullable();
            $table->string('account_reference', 40)->nullable()->index();
            $table->string('reference', 60)->nullable();
            $table->integer('result_code')->nullable();
            $table->string('result_description')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('allocated_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['package_booking_id', 'status']);
        });

        Schema::create('travel_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_cancellation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('travel_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->string('status', 20)->default('requested')->index();
            $table->string('method', 10)->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('daraja_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->json('payload');
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('travel_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('package_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->date('occurred_on');
            $table->string('type', 30);
            $table->string('severity', 10);
            $table->text('description');
            $table->text('resolution')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_incidents');
        Schema::dropIfExists('daraja_callbacks');
        Schema::dropIfExists('travel_refunds');
        Schema::dropIfExists('travel_payments');
        Schema::dropIfExists('package_cancellations');
        Schema::dropIfExists('booking_checklist_items');
        Schema::dropIfExists('package_bookings');
        Schema::dropIfExists('travel_clients');
        Schema::dropIfExists('influencer_codes');
        Schema::dropIfExists('influencers');
    }
};
