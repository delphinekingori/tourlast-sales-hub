<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A provider that signed up on tourlast.com, mirrored from the tourlast.com
     * data source. The Hub never edits the provider itself, only who gets credit.
     */
    public function up(): void
    {
        Schema::create('onboardings', function (Blueprint $table) {
            $table->id();
            $table->string('tourlast_property_id', 100)->unique();
            $table->string('ref_code', 40)->nullable()->index();
            $table->foreignId('referral_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('attribution', 20)->default('referral');
            $table->string('property_name');
            $table->string('property_type', 50)->default('other')->index();
            $table->string('location')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status', 30)->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('active_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('credited_at')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'credited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboardings');
    }
};
