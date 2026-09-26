<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('property_engagement_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_engagement_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title', 100)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_key', 20)->nullable()->index();
            $table->string('whatsapp', 40)->nullable();
            $table->string('email')->nullable()->index();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_decision_maker')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_engagement_id', 'is_primary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_engagement_contacts');
    }
};
