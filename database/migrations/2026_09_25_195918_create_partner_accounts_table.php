<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One legal business on tourlast.com. All its properties share one Account
     * and one set of points (Schedule 1, paragraphs 3.2 and 3.3).
     */
    public function up(): void
    {
        Schema::create('partner_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_key', 120)->unique();
            $table->string('legal_name');
            $table->string('category', 20)->default('stay');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('activation_date')->nullable()->index();
            $table->unsignedInteger('activation_inventory')->nullable();
            $table->string('inventory_basis', 30)->default('rooms');
            $table->string('inventory_note')->nullable();
            $table->string('qualification_status', 20)->default('pending')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('review_failed_at')->nullable();
            $table->string('review_failed_reason')->nullable();
            $table->foreignId('review_failed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_warning_at')->nullable();
            $table->string('review_warning')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('partner_accounts')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_accounts');
    }
};
