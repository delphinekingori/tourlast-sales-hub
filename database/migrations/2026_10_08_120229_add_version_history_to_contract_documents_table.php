<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract files are never deleted: a replaced file points at its newer
 * version, and a removed file is only marked as removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_documents', function (Blueprint $table) {
            $table->string('mime_type', 120)->nullable()->after('size');
            $table->foreignId('replaced_by_id')->nullable()->after('uploaded_by')->constrained('contract_documents')->nullOnDelete();
            $table->timestamp('removed_at')->nullable()->after('replaced_by_id');
            $table->foreignId('removed_by')->nullable()->after('removed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_by');
            $table->dropColumn('removed_at');
            $table->dropConstrainedForeignId('replaced_by_id');
            $table->dropColumn('mime_type');
        });
    }
};
