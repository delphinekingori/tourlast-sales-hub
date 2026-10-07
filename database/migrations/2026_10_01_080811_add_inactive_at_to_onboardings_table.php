<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The moment tourlast.com says the property stopped being live: the date an
     * Inactive status is history from, and the credit already earned is kept.
     */
    public function up(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->timestamp('inactive_at')->nullable()->after('active_at');
        });
    }

    public function down(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropColumn('inactive_at');
        });
    }
};
