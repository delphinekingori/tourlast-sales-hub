<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_account_id')->constrained()->cascadeOnDelete();
            $table->string('item', 40);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 10)->default('manual');
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['partner_account_id', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_checklist_items');
    }
};
