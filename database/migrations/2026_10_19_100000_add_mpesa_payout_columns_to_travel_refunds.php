<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_refunds', function (Blueprint $table) {
            $table->string('originator_conversation_id', 64)->nullable()->unique()->after('failure_reason');
            $table->string('conversation_id', 64)->nullable()->after('originator_conversation_id');
            $table->string('payout_phone', 12)->nullable()->after('conversation_id');
            $table->timestamp('payout_requested_at')->nullable()->after('payout_phone');
            $table->timestamp('payout_flagged_at')->nullable()->after('payout_requested_at');
        });

        Schema::table('daraja_callbacks', function (Blueprint $table) {
            $table->foreignId('travel_refund_id')->nullable()->after('travel_payment_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daraja_callbacks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('travel_refund_id');
        });

        Schema::table('travel_refunds', function (Blueprint $table) {
            $table->dropColumn(['originator_conversation_id', 'conversation_id', 'payout_phone', 'payout_requested_at', 'payout_flagged_at']);
        });
    }
};
