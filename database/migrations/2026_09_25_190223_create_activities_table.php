<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->timestamp('happened_at')->index();
            $table->text('notes')->nullable();
            $table->string('next_action')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
