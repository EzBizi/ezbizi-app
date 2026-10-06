<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Workiz v2 holds beyond jobs, clients, leads and the team: invoices,
 * payments, estimates, time off, calendar events, timesheets, custom-field
 * definitions and the price book (Jose, 2026-10-06: "take advantage of
 * everything v2 has to offer").
 *
 * One table, typed by `type`, because every one of them is the same shape to
 * a reader: a Workiz record, usually about a job, sometimes about a
 * technician, with a date, an amount and a status. The columns are the ones a
 * reader filters on; everything else stays in `payload`, as Workiz sent it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workiz_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();

            // Keyed on the franchise, not the connection: a reconnect mints a
            // new connection id, and a key holding it would land every record
            // a second time (workiz_jobs, 2026-09-14).
            $table->uuid('franchise_id');
            $table->uuid('connection_id')->nullable();

            // invoice, payment, estimate, time_off, event, timesheet,
            // custom_field, item.
            $table->string('type', 20);

            // Workiz's v2 id: IV-, PAY-, ES-, TS-, CF-, ITM-…
            $table->string('remote_id', 64);

            // The Workiz job it belongs to (JOB-…), and the EzBizi job that
            // job became, filled once the job is mirrored.
            $table->string('job_remote_id', 64)->nullable()->index();
            $table->foreignUuid('job_id')->nullable()->constrained('jobs')->nullOnDelete();

            // The technician: who collected a payment, who is off, whose
            // timesheet. USR-…, as workiz_team_members.remote_id holds it.
            $table->string('user_remote_id', 64)->nullable();

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('amount_due', 12, 2)->nullable();
            $table->string('status', 64)->nullable();
            $table->dateTime('remote_updated_at')->nullable();
            $table->json('payload');
            $table->dateTime('synced_at');
            $table->timestamps();

            $table->unique(['franchise_id', 'type', 'remote_id']);
            $table->index(['franchise_id', 'type', 'starts_at']);
        });

        // A record names its job by v2 id, which lives in the job mirror's
        // payload. Indexed, so linking a page of records is one join.
        Schema::table('workiz_jobs', function (Blueprint $table) {
            $table->string('v2_id', 64)->nullable()
                ->virtualAs("json_unquote(json_extract(`payload`, '$.v2.id'))")
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('workiz_jobs', function (Blueprint $table) {
            $table->dropIndex(['v2_id']);
            $table->dropColumn('v2_id');
        });
        Schema::dropIfExists('workiz_records');
    }
};
