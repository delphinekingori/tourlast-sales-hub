<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An influencer can be on several platforms, each with its own handle.
     * influencers.platform/handle stay as a copy of the first one (lists,
     * exports and the API read them).
     */
    public function up(): void
    {
        Schema::create('influencer_platforms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30);
            $table->string('handle', 120)->nullable();
            $table->string('url')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['influencer_id', 'platform']);
            $table->index('platform');
        });

        DB::table('influencers')->whereNotNull('platform')->orderBy('id')->each(function (object $influencer): void {
            DB::table('influencer_platforms')->insert([
                'influencer_id' => $influencer->id,
                'platform' => $influencer->platform,
                'handle' => $influencer->handle,
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('influencer_platforms');
    }
};
