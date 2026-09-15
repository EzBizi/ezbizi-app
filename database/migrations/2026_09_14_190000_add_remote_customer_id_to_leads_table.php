<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remember which Google Ads account an enquiry came from.
 *
 * `leads.connection_id` is ON DELETE SET NULL, so disconnecting an account does
 * not delete its enquiries - it detaches them. Reconnecting makes a NEW
 * social_connections row, and nothing on the lead says which account it belongs
 * to, so the only way to re-attach it was to make Google send the lead again.
 * That costs quota, is bounded by a date window, and silently leaves anything
 * outside that window detached for good.
 *
 * On 2026-09-14 that cost three rounds of it: a disconnect orphaned 41,006
 * leads, two forced re-reads reclaimed 5,430, and a second reconnect eight
 * minutes later dropped them back to 2,547 because the connection rows had been
 * replaced underneath.
 *
 * With the account's own id on the row, a reconnect re-links locally and
 * instantly - no API call, no window, nothing to schedule.
 *
 * ── What this can and cannot repair ──────────────────────────────
 *
 * The backfill below stamps every lead that still HAS a connection, from that
 * connection. It cannot stamp an orphan, because the fact it needs is the one
 * that was lost. Those rows get their id the next time Google returns them, and
 * the ones Google will not return again stay as they are: readable, searchable,
 * filed to the right company, and not repliable.
 *
 * Recording that explicitly because the shape of this bug twice today was a fix
 * that was right about the mechanism and silent about the rows already on the
 * wrong side of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Google's customer id is ten digits today. Kept as a string
            // because it is an identifier rather than a number - nothing adds
            // to it, and leading zeros would be a real defect if they ever
            // appear.
            $table->string('remote_customer_id', 32)->nullable()->after('connection_id');

            // The reclaim looks up orphans by organisation and customer id, so
            // the index carries both. Leads is the largest table in the
            // product and this query runs on every sync tick.
            $table->index(['organization_id', 'remote_customer_id'], 'leads_org_remote_customer_index');
        });

        // Stamp what is still knowable. Chunked rather than one UPDATE ... JOIN
        // so this stays readable and does not hold a long lock on the biggest
        // table in the database.
        DB::table('social_connections')
            ->where('provider', 'google_ads')
            ->select('id', 'remote_id')
            ->orderBy('id')
            ->chunk(100, function ($connections) {
                foreach ($connections as $connection) {
                    if (! is_string($connection->remote_id) || $connection->remote_id === '') {
                        continue;
                    }

                    DB::table('leads')
                        ->where('connection_id', $connection->id)
                        ->whereNull('remote_customer_id')
                        ->update(['remote_customer_id' => $connection->remote_id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_org_remote_customer_index');
            $table->dropColumn('remote_customer_id');
        });
    }
};
