<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('region');
            $table->string('job_title', 120)->nullable()->after('avatar_path');
            $table->string('bio', 500)->nullable()->after('job_title');
            $table->string('emergency_contact_name', 120)->nullable()->after('bio');
            $table->string('emergency_contact_phone', 32)->nullable()->after('emergency_contact_name');
            $table->timestamp('last_seen_at')->nullable()->index()->after('last_login_at');
        });

        Schema::table('incentive_agreements', function (Blueprint $table) {
            $table->timestamp('expiry_alert_sent_at')->nullable();
        });

        Schema::table('onboardings', function (Blueprint $table) {
            $table->timestamp('first_booking_at')->nullable()->after('rejected_at');
        });

        Schema::table('sandbox_providers', function (Blueprint $table) {
            $table->timestamp('first_booking_at')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'job_title', 'bio', 'emergency_contact_name', 'emergency_contact_phone', 'last_seen_at']);
        });
        Schema::table('incentive_agreements', fn (Blueprint $table) => $table->dropColumn('expiry_alert_sent_at'));
        Schema::table('onboardings', fn (Blueprint $table) => $table->dropColumn('first_booking_at'));
        Schema::table('sandbox_providers', fn (Blueprint $table) => $table->dropColumn('first_booking_at'));
    }
};
