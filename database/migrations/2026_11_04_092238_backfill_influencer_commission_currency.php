<?php

use App\Models\FlightBooking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Commission is paid in the booking's own currency and never converted:
     * a flight line takes its flight's currency (USD when it has none) and
     * everything else is KES. Earlier lines took the package's currency.
     */
    public function up(): void
    {
        $flight = (new FlightBooking)->getMorphClass();

        DB::table('influencer_commissions')->where('bookable_type', '!=', $flight)->update(['currency' => 'KES']);

        DB::table('influencer_commissions')
            ->where('bookable_type', $flight)
            ->orderBy('id')
            ->each(function (object $line): void {
                $currency = strtoupper(trim((string) DB::table('flight_bookings')->where('id', $line->bookable_id)->value('currency'))) ?: 'USD';

                DB::table('influencer_commissions')->where('id', $line->id)->update(['currency' => $currency]);
            });
    }

    public function down(): void
    {
        //
    }
};
