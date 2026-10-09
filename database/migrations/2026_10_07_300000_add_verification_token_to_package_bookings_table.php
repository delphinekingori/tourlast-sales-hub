<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * An unguessable token per booking for the public ticket verification
     * page (/booking/verify/{token}), so bookings cannot be enumerated by
     * reference or id.
     */
    public function up(): void
    {
        Schema::table('package_bookings', function (Blueprint $table) {
            $table->string('verification_token', 40)->nullable()->after('reference');
        });

        DB::table('package_bookings')->whereNull('verification_token')->orderBy('id')->each(function (object $booking): void {
            DB::table('package_bookings')->where('id', $booking->id)->update(['verification_token' => Str::random(32)]);
        });

        Schema::table('package_bookings', function (Blueprint $table) {
            $table->unique('verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('package_bookings', function (Blueprint $table) {
            $table->dropUnique(['verification_token']);
            $table->dropColumn('verification_token');
        });
    }
};
