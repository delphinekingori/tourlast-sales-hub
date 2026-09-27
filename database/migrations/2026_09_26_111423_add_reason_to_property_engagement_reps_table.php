<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a salesperson was assigned to (or took over) a registry property.
     */
    public function up(): void
    {
        Schema::table('property_engagement_reps', function (Blueprint $table) {
            $table->string('reason', 60)->nullable()->after('assigned_by');
            $table->text('notes')->nullable()->after('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_engagement_reps', function (Blueprint $table) {
            $table->dropColumn(['reason', 'notes']);
        });
    }
};
