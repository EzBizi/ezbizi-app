<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A specimen enquiry says so on the row.
 *
 * `ezbizi:thumbtack-test-lead` asks Thumbtack to send a real webhook — that is
 * the point of it, and it is why the pipe is proven end to end rather than
 * against a fixture. But the lead it produces carries a genuine Thumbtack
 * negotiation id and lands in `leads` looking exactly like a customer.
 *
 * On 2026-09-10 that nearly put a false claim on the marketing site: the daily
 * catalog asked whether a second lead source had arrived, read
 * `thumbtack = 1` from a query that filtered only `remote_id NOT LIKE 'DEMO-%'`,
 * and the deploy desk settled it by comparing the phone number against a
 * control. "Has a customer enquiry arrived" should be answerable in SQL by
 * anybody:
 *
 *     SELECT source, COUNT(*) FROM leads WHERE is_test = 0 GROUP BY source;
 *
 * Same column name and meaning as `automation_runs.is_test`, which the client
 * already draws as "Rehearsal".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
