<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Account status: active, suspended (optionally until a date) or
     * terminated. is_active stays in step so existing sign-in checks work.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status', 20)->default('active')->after('is_active')->index();
            $table->date('suspended_until')->nullable()->after('account_status');
            $table->timestamp('status_changed_at')->nullable()->after('suspended_until');
        });

        DB::table('users')->where('is_active', false)->update(['account_status' => 'suspended']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_status']);
            $table->dropColumn(['account_status', 'suspended_until', 'status_changed_at']);
        });
    }
};
