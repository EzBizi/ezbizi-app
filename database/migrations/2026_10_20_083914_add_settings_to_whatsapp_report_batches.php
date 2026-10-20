<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A report draft's corrections (ReportChanges), and who made each, kept on its batch (2026-10-20). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_report_batches', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('revision');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_report_batches', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
