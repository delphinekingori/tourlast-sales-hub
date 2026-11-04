<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The agreement dates a statement was worked out on, so a paid month is
     * never re-priced when agreement dates are edited afterwards.
     */
    public function up(): void
    {
        Schema::table('payout_statements', function (Blueprint $table): void {
            $table->json('agreement_windows')->nullable()->after('adjustment_lines');
            $table->timestamp('compliance_reviewed_at')->nullable()->after('compliance');
        });
    }

    public function down(): void
    {
        Schema::table('payout_statements', function (Blueprint $table): void {
            $table->dropColumn(['agreement_windows', 'compliance_reviewed_at']);
        });
    }
};
