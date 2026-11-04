<?php

use App\Models\FlightBooking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Flight commission is USD and everything else KES, never converted.
     * Earlier lines took the booking's currency, so set them to the rule.
     */
    public function up(): void
    {
        $flight = (new FlightBooking)->getMorphClass();

        DB::table('influencer_commissions')->where('bookable_type', $flight)->update(['currency' => 'USD']);
        DB::table('influencer_commissions')->where('bookable_type', '!=', $flight)->update(['currency' => 'KES']);
    }

    public function down(): void
    {
        //
    }
};
