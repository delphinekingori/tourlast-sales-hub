<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->index();
            $table->string('provider_type', 30);
            $table->string('business_name')->nullable();
            $table->string('trading_name')->nullable();
            $table->string('registration_number', 60)->nullable();
            $table->string('kra_pin', 20)->nullable();
            $table->string('country', 60)->default('Kenya');
            $table->string('region', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('address')->nullable();
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone_key', 20)->nullable()->index();
            $table->string('whatsapp', 30)->nullable();
            $table->string('primary_contact_name')->nullable();
            $table->string('primary_contact_phone', 30)->nullable();
            $table->string('primary_contact_email')->nullable();
            $table->string('decision_maker_name')->nullable();
            $table->string('decision_maker_phone', 30)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('prospect')->index();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('property_engagement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'status']);
        });

        Schema::create('provider_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number', 40)->unique();
            $table->foreignId('travel_provider_id')->constrained()->cascadeOnDelete();
            $table->string('contract_type', 60);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('commission_model', 20)->default('percentage');
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('fixed_commission', 12, 2)->nullable();
            $table->string('currency', 3)->default('KES');
            $table->text('payment_terms')->nullable();
            $table->text('settlement_terms')->nullable();
            $table->text('cancellation_terms')->nullable();
            $table->text('refund_terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedSmallInteger('last_expiry_alert_days')->nullable();
            $table->timestamps();
            $table->index(['status', 'ends_on']);
        });

        Schema::create('contract_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_contract_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_documents');
        Schema::dropIfExists('provider_contracts');
        Schema::dropIfExists('travel_providers');
    }
};
