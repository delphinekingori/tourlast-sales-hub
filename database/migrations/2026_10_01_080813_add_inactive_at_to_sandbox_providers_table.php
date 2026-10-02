<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sandbox feed carries the same inactive_at row the source apps send.
     */
    public function up(): void
    {
        Schema::table('sandbox_providers', function (Blueprint $table) {
            $table->timestamp('inactive_at')->nullable()->after('active_at');
        });
    }

    public function down(): void
    {
        Schema::table('sandbox_providers', function (Blueprint $table) {
            $table->dropColumn('inactive_at');
        });
    }
};
