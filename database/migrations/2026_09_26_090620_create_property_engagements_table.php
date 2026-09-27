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
        Schema::create('property_engagements', function (Blueprint $table) {
            $table->id();

            // Property information
            $table->string('name');
            $table->string('name_key')->index();
            $table->string('property_type', 50)->default('other')->index();
            $table->string('star_rating', 10)->nullable();
            $table->string('tourlast_property_id', 100)->nullable()->index();
            $table->string('website')->nullable();
            $table->string('website_key')->nullable()->index();
            $table->string('trading_name')->nullable();
            $table->string('registration_name')->nullable();
            $table->string('registration_number', 100)->nullable()->index();
            $table->string('kra_pin', 30)->nullable()->index();
            $table->unsignedInteger('rooms')->nullable();
            $table->unsignedInteger('capacity')->nullable();

            // Location
            $table->string('country', 100)->index();
            $table->string('region', 100)->index();
            $table->string('city', 100)->index();
            $table->string('area', 150)->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Engagement
            $table->foreignId('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage', 40)->index();
            $table->string('status', 30)->index();
            $table->string('source', 40)->nullable()->index();
            $table->text('summary')->nullable();
            $table->string('next_action')->nullable();
            $table->date('next_action_on')->nullable();
            $table->date('first_engaged_on')->index();
            $table->date('last_engaged_on')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sales_rep_id', 'status']);
            $table->index(['stage', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_engagements');
    }
};
