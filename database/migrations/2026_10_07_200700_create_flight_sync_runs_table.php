<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log of flight booking syncs (pulls and pushes from Flights Super Admin).
     * Kept apart from sync_runs, which the tourlast.com sync reads to work out
     * its own "changed since" time.
     */
    public function up(): void
    {
        Schema::create('flight_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('mode', 20);
            $table->string('status', 20)->index();
            $table->timestamp('changed_since')->nullable();
            $table->unsignedInteger('records_seen')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_sync_runs');
    }
};
