<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which report each message went out for (Jose, 2026-11-25: "right now we are
 * sending out a lot of reports on whatsapp, and yet they can't be seen on the
 * app"). A WhatsApp message keeps its report's key; an emailed report, of
 * which no copy was kept anywhere, gets a row of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('report', 100)->nullable()->after('template_name');
            $table->index(['organization_id', 'report', 'created_at']);
        });

        // A rule's report text already named its rule in its key
        // (report.text:<rule>:<kind>:<day>:<user>), so those go back as far as they were kept.
        DB::table('whatsapp_messages')
            ->where('idempotency_key', 'like', 'report.text:%')
            ->whereNull('report')
            ->update(['report' => DB::raw("CONCAT('automation:', SUBSTRING(idempotency_key, 13, 36))")]);

        Schema::create('report_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('report', 100);
            $table->json('recipients')->nullable();
            $table->string('subject', 255);
            $table->longText('body')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->index(['organization_id', 'report', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_emails');
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'report', 'created_at']);
            $table->dropColumn('report');
        });
    }
};
