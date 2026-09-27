<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sandbox_providers', function (Blueprint $table) {
            $table->string('account_id', 100)->nullable()->after('property_id');
            $table->string('legal_name')->nullable()->after('property_name');
            $table->string('category', 20)->nullable()->after('property_type');
            $table->unsignedInteger('inventory_count')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('sandbox_providers', function (Blueprint $table) {
            $table->dropColumn(['account_id', 'legal_name', 'category', 'inventory_count']);
        });
    }
};
