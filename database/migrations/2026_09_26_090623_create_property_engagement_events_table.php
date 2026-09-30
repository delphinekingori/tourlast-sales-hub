<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The registry timeline. Rows are only ever added, never edited, so the
     * table is also the audit log for stage, status, rep and detail changes.
     */
    public function up(): void
    {
        Schema::create('property_engagement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_engagement_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->string('summary')->nullable();
            $table->text('notes')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->index(['property_engagement_id', 'happened_at'], 'pee_engagement_happened_idx');
            $table->index('sales_rep_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_engagement_events');
    }
};
