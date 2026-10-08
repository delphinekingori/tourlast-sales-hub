<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencer_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('influencer_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            $table->morphs('bookable');
            $table->decimal('booking_amount', 12, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('earned_at');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference', 60)->nullable();
            $table->timestamps();
            $table->unique(['influencer_code_id', 'bookable_type', 'bookable_id'], 'influencer_commission_booking_unique');
        });

        /*
         * Targets gain a metric so Travel Sales targets (set by Sales Admin)
         * live beside the existing points targets. Existing rows are "points".
         */
        Schema::table('targets', function (Blueprint $table) {
            $table->string('metric', 30)->default('points')->after('month');
            $table->unsignedBigInteger('target_value')->nullable()->after('target');
            $table->foreignId('set_by')->nullable()->after('target_value')->constrained('users')->nullOnDelete();
        });

        Schema::table('targets', function (Blueprint $table) {
            $table->index('user_id');
            $table->dropUnique(['user_id', 'month']);
            $table->unique(['user_id', 'month', 'metric']);
        });

        /*
         * Follow-ups can belong to a travel record (provider, package, booking,
         * client or flight booking) instead of a lead.
         */
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->change();
            $table->nullableMorphs('subject');
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropMorphs('subject');
        });

        Schema::table('targets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'month', 'metric']);
            $table->unique(['user_id', 'month']);
            $table->dropIndex(['user_id']);
            $table->dropConstrainedForeignId('set_by');
            $table->dropColumn(['metric', 'target_value']);
        });

        Schema::dropIfExists('influencer_commissions');
    }
};
