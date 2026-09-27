<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Follow-ups become schedule items: a call, meeting or site visit at a set
     * time with a contact. Existing follow-ups stay as "anytime" reminders.
     */
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->string('type', 30)->default('follow_up')->after('user_id');
            $table->boolean('has_time')->default(false)->after('due_at');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('has_time');
            $table->string('contact_name')->nullable()->after('duration_minutes');
            $table->string('contact_role', 120)->nullable()->after('contact_name');
            $table->string('location')->nullable()->after('contact_role');
            $table->text('notes')->nullable()->after('location');
            $table->foreignId('outcome_activity_id')->nullable()->after('completed_at')->constrained('activities')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outcome_activity_id');
            $table->dropColumn(['type', 'has_time', 'duration_minutes', 'contact_name', 'contact_role', 'location', 'notes']);
        });
    }
};
