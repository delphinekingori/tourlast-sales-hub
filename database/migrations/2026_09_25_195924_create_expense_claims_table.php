<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Airtime claims, transport reimbursements (after the trip, with receipts)
     * and transport requests to Finance (before the trip).
     */
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->date('month')->index();
            $table->decimal('amount', 10, 2);
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->string('description', 1000);
            $table->date('travel_date')->nullable();
            $table->string('ride_provider', 20)->nullable();
            $table->string('trip_reference', 100)->nullable();
            $table->string('pickup')->nullable();
            $table->string('dropoff')->nullable();
            $table->decimal('distance_km', 7, 1)->nullable();
            $table->foreignId('partner_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('current_step', 20)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claims');
    }
};
