<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LeadThread::refresh now reads a customer's call followed by our message as
 * answered (Jose, 2026-11-17). Same rule for the rows already written, so
 * Leads › Recovery stops showing "Awaiting reply" on leads we texted after a call.
 */
return new class extends Migration
{
    public function up(): void
    {
        $newest = fn (string $col, string $extra = '') => "(SELECT CONCAT(c.$col) FROM lead_conversations c WHERE c.lead_id = leads.id $extra ORDER BY c.occurred_at DESC, c.id DESC LIMIT 1)";

        DB::table('leads')->where('last_contact_from', 'CONSUMER')
            ->whereRaw($newest('participant')." = 'ADVERTISER'")
            ->whereRaw($newest("participant, '|', c.channel", 'AND c.is_automatic = 0')." = 'CONSUMER|PHONE_CALL'")
            ->update(['last_contact_from' => 'ADVERTISER']);
    }

    public function down(): void
    {
        // Rows were dumped before the deploy; LeadThread::refresh rewrites them anyway.
    }
};
