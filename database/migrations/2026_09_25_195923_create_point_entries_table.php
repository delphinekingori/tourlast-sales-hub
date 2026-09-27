<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The points ledger. Point values are never edited: a correction cancels the
     * old line and adds a new one.
     */
    public function up(): void
    {
        Schema::create('point_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('points', 5, 1);
            $table->timestamp('earned_on');
            $table->date('month')->index();
            $table->unsignedTinyInteger('bonus_week');
            $table->string('status', 20)->default('provisional')->index();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'month', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_entries');
    }
};
