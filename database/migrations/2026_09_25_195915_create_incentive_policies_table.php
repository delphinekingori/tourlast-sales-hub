<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned incentive schedules. A new version never changes past months.
     */
    public function up(): void
    {
        Schema::create('incentive_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('effective_from')->index();
            $table->json('rules');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incentive_policies');
    }
};
