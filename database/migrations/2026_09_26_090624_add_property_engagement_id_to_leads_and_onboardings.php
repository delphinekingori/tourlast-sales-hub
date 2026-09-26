<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leads and onboardings can point at the registry record for the property.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('property_engagement_id')->nullable()->after('onboarding_id')->constrained()->nullOnDelete();
        });

        Schema::table('onboardings', function (Blueprint $table) {
            $table->foreignId('property_engagement_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_engagement_id');
        });

        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_engagement_id');
        });
    }
};
