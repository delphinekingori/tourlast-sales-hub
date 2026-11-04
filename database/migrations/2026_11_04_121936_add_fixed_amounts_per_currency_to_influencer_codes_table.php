<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fixed commission is set per currency by the admin. Existing fixed codes
     * keep their amount in the currency they were paid in: USD on a flights
     * code, KES otherwise. Admins should review fixed "All" codes.
     */
    public function up(): void
    {
        Schema::table('influencer_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('influencer_codes', 'fixed_kes')) {
                $table->decimal('fixed_kes', 12, 2)->nullable()->after('commission_value');
            }

            if (! Schema::hasColumn('influencer_codes', 'fixed_usd')) {
                $table->decimal('fixed_usd', 12, 2)->nullable()->after('fixed_kes');
            }
        });

        DB::table('influencer_codes')->where('commission_type', 'fixed')->where('applies_to', 'flights')->update(['fixed_usd' => DB::raw('commission_value')]);
        DB::table('influencer_codes')->where('commission_type', 'fixed')->where('applies_to', '!=', 'flights')->update(['fixed_kes' => DB::raw('commission_value')]);
    }

    public function down(): void
    {
        Schema::table('influencer_codes', function (Blueprint $table) {
            $table->dropColumn(['fixed_kes', 'fixed_usd']);
        });
    }
};
