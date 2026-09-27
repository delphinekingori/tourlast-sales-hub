<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One statement per salesperson per month. Figures are frozen when the
     * statement is approved; later cancellations are recovered on a later statement.
     */
    public function up(): void
    {
        Schema::create('payout_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->foreignId('incentive_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('points', 6, 1)->default(0);
            $table->json('weekly_points')->nullable();
            $table->unsignedInteger('retainer')->default(0);
            $table->unsignedInteger('weekly_bonus')->default(0);
            $table->unsignedInteger('monthly_bonus')->default(0);
            $table->unsignedInteger('exceptional')->default(0);
            $table->decimal('airtime', 10, 2)->default(0);
            $table->decimal('transport', 10, 2)->default(0);
            $table->decimal('adjustments', 10, 2)->default(0);
            $table->json('adjustment_lines')->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->json('compliance')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->decimal('recovered_amount', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_statements');
    }
};
