<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * A caller's own name for one outbound message ("weekly-report:org:2026-10-12:+1305..."),
     * so a retried job or a second scheduler run finds the row it already sent
     * instead of texting the person twice. Unique, so two racing senders
     * cannot both write one.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->unique()->after('wamid');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
