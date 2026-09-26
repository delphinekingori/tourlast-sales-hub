<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stand-in for the tourlast.com provider table while the real connection
     * is not configured (TOURLAST_SOURCE=sandbox). The sync reads from here
     * exactly as it would read from tourlast.com.
     */
    public function up(): void
    {
        Schema::create('sandbox_providers', function (Blueprint $table) {
            $table->id();
            $table->string('property_id', 100)->unique();
            $table->string('ref_code', 40)->nullable();
            $table->string('property_name');
            $table->string('property_type', 50);
            $table->string('location')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status', 30);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('active_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_providers');
    }
};
