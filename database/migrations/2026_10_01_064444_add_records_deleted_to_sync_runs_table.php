<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Counts the properties a sync archived after the source app reported them deleted.
     */
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->unsignedInteger('records_deleted')->default(0)->after('records_updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn('records_deleted');
        });
    }
};
