<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 20)->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('kind', 10)->default('image');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('alt_text')->nullable();
            $table->foreignId('travel_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('destination', 120)->nullable()->index();
            $table->string('category', 20)->default('other')->index();
            $table->json('tags')->nullable();
            $table->string('source')->nullable();
            $table->string('copyright_owner')->nullable();
            $table->string('usage_permission', 20)->default('granted');
            $table->text('usage_notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['travel_provider_id', 'category']);
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('vehicle')->nullable();
            $table->string('vehicle_registration', 20)->nullable();
            $table->string('license_number', 40)->nullable();
            $table->foreignId('travel_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('guides', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->json('languages')->nullable();
            $table->string('specialization')->nullable();
            $table->foreignId('travel_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guides');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('media_assets');
    }
};
