<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember which availability alert a departure last raised (so "nearly
     * full" and "full" go out once) and the last day a booking's pre-trip
     * reminder was sent (at most one a day).
     */
    public function up(): void
    {
        Schema::table('package_departures', function (Blueprint $table) {
            $table->string('last_availability_alert', 20)->nullable()->after('overbooking_approved_by');
        });

        Schema::table('package_bookings', function (Blueprint $table) {
            $table->date('last_pretrip_alert_on')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('package_bookings', function (Blueprint $table) {
            $table->dropColumn('last_pretrip_alert_on');
        });

        Schema::table('package_departures', function (Blueprint $table) {
            $table->dropColumn('last_availability_alert');
        });
    }
};
