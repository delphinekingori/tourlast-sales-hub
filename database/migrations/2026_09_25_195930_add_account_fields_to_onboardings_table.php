<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->foreignId('partner_account_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('tourlast_account_id', 100)->nullable()->after('tourlast_property_id')->index();
            $table->string('legal_name')->nullable()->after('property_name');
            $table->string('category', 20)->nullable()->after('property_type');
            $table->unsignedInteger('inventory_count')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_account_id');
            $table->dropColumn(['tourlast_account_id', 'legal_name', 'category', 'inventory_count']);
        });
    }
};
