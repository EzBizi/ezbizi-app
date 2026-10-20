<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exact PDFs a report batch's preview showed, kept so what is approved is
 * what is sent (the takeover's proof layer, 2026-10-20). Each REFRESH is a new
 * revision; an approval names the revision and its consolidated PDF's hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_batch_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('batch_id')->constrained('whatsapp_report_batches')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            // The branch; null is the consolidated report.
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('filename');
            $table->string('path');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->index(['batch_id', 'revision']);
        });

        Schema::table('whatsapp_report_batches', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(0)->after('ends_on');
            $table->unsignedInteger('approved_revision')->nullable()->after('approved_by');
            $table->char('approved_sha256', 64)->nullable()->after('approved_revision');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_report_batches', function (Blueprint $table) {
            $table->dropColumn(['revision', 'approved_revision', 'approved_sha256']);
        });
        Schema::dropIfExists('report_batch_files');
    }
};
