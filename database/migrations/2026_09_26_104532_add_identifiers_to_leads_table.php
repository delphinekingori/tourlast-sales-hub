<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Business identifiers on leads, plus normalised match keys so the
     * Hub-wide duplicate check can compare leads quickly at scale.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('trading_name')->nullable()->after('business_name');
            $table->string('website')->nullable()->after('contact_email');
            $table->string('registration_number', 100)->nullable()->after('website')->index();
            $table->string('kra_pin', 30)->nullable()->after('registration_number')->index();
            $table->string('name_key')->nullable()->after('trading_name')->index();
            $table->string('phone_key', 20)->nullable()->after('contact_phone')->index();
            $table->string('website_key')->nullable()->after('website')->index();
        });

        DB::table('leads')->orderBy('id')->each(function (object $lead): void {
            $digits = preg_replace('/\D/', '', (string) $lead->contact_phone);

            DB::table('leads')->where('id', $lead->id)->update([
                'name_key' => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $lead->business_name))))),
                'phone_key' => strlen($digits) >= 7 ? substr($digits, -9) : null,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['registration_number']);
            $table->dropIndex(['kra_pin']);
            $table->dropIndex(['name_key']);
            $table->dropIndex(['phone_key']);
            $table->dropIndex(['website_key']);
            $table->dropColumn(['trading_name', 'website', 'registration_number', 'kra_pin', 'name_key', 'phone_key', 'website_key']);
        });
    }
};
