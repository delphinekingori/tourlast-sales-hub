<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_claim_id')->constrained()->cascadeOnDelete();
            $table->string('step', 20);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('decision', 20);
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_approvals');
    }
};
