<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_booking_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('full_name', 120);
            $table->string('type', 10)->default('adult');
            $table->boolean('is_booker')->default(false);
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 60)->nullable();
            $table->text('id_number')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 160)->nullable();
            $table->text('special_requirements')->nullable();
            $table->timestamps();
            $table->index(['package_booking_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_booking_guests');
    }
};
