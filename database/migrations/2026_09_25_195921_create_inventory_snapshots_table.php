<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rooms, units or services an Account had live on a date. Growth after the
     * Activation Date drives 90-day expansion points.
     */
    public function up(): void
    {
        Schema::create('inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('count');
            $table->timestamp('live_on');
            $table->string('source', 10);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['partner_account_id', 'live_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshots');
    }
};
