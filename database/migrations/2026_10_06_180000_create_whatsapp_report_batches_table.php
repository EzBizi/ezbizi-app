<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly and monthly PDFs wait for the bookkeeper's "APPROVE" on WhatsApp
 * before they reach the groups (docs/REPLACING-RODRIGO-BOT.md, row C).
 *
 * And a held report can be a document as well as text: `media_id` is Meta's
 * id for the uploaded PDF, valid 30 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_report_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period', 8);            // 'week' | 'month'
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'period', 'starts_on'], 'whatsapp_report_batches_once');
        });

        Schema::table('whatsapp_pending_reports', function (Blueprint $table) {
            $table->string('media_id', 64)->nullable()->after('body');
            $table->string('filename', 128)->nullable()->after('media_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_pending_reports', function (Blueprint $table) {
            $table->dropColumn(['media_id', 'filename']);
        });
        Schema::dropIfExists('whatsapp_report_batches');
    }
};
