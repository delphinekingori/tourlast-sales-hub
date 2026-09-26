<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a property said no (objection, competitor, notes), when, and when to
     * try again, on leads and on registry records.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('objection', 40)->nullable()->after('lost_reason')->index();
            $table->string('competitor', 100)->nullable()->after('objection');
            $table->text('lost_notes')->nullable()->after('competitor');
            $table->timestamp('lost_at')->nullable()->after('lost_notes')->index();
            $table->date('reengage_on')->nullable()->after('lost_at')->index();
        });

        Schema::table('property_engagements', function (Blueprint $table) {
            $table->string('objection', 40)->nullable()->after('summary')->index();
            $table->string('competitor', 100)->nullable()->after('objection');
            $table->text('outcome_notes')->nullable()->after('competitor');
            $table->timestamp('closed_at')->nullable()->after('outcome_notes')->index();
            $table->date('reengage_on')->nullable()->after('closed_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['objection']);
            $table->dropIndex(['lost_at']);
            $table->dropIndex(['reengage_on']);
            $table->dropColumn(['objection', 'competitor', 'lost_notes', 'lost_at', 'reengage_on']);
        });

        Schema::table('property_engagements', function (Blueprint $table) {
            $table->dropIndex(['objection']);
            $table->dropIndex(['closed_at']);
            $table->dropIndex(['reengage_on']);
            $table->dropColumn(['objection', 'competitor', 'outcome_notes', 'closed_at', 'reengage_on']);
        });
    }
};
