<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A report batch the approver put on hold (HOLD), until they approve or refresh it (2026-10-19). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_report_batches', function (Blueprint $table) {
            $table->timestamp('held_at')->nullable()->after('approved_by');
            $table->foreignUuid('held_by')->nullable()->after('held_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_report_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('held_by');
            $table->dropColumn('held_at');
        });
    }
};
