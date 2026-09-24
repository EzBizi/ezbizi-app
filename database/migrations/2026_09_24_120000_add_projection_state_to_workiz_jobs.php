<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remember what the projection last wrote into each `jobs` row.
 *
 * ── THE BUG THIS FIXES ──────────────────────────────────────────
 *
 * Jose, 2026-09-24: *"all the jobs show as submitted, so the status aren't
 * updating"*. The projection was insert-only, so a job stayed at whatever
 * status it had the moment it was first projected. Measured on production
 * the same day: 147 projected jobs already disagreed with the mirror, 63 of
 * them mirror "In progress" and shown "Submitted".
 *
 * Insert-only was an over-reading of the house rule "a recurring sweep never
 * overwrites a field a person has touched". That rule protects a PERSON'S
 * choice. Workiz's own status is not a person's choice in EzBizi, and it is
 * the one field this whole integration exists to carry.
 *
 * ── HOW THE TWO ARE TOLD APART ──────────────────────────────────
 *
 * `projected` holds the values the projection last wrote. On each run, a field
 * follows Workiz only while the `jobs` row still holds exactly that value —
 * which means nobody in EzBizi has changed it since. A field a person changed
 * no longer matches and is left alone, per field, so fixing a price by hand
 * does not stop the status from following Workiz.
 *
 * ── THE BACKFILL IS SAFE, AND THAT WAS MEASURED ─────────────────
 *
 * Every existing projected row gets `projected` = the job's CURRENT values.
 * That treats every current value as one the projection wrote, which is true
 * only if nobody has edited an imported job. Measured on production
 * 2026-09-24: **0 audit rows** for any edit to an imported job — and none
 * could have been made, because A-1's status list does not yet contain
 * Workiz's statuses and the Jobs API refuses a save whose status is not
 * allowed.
 *
 * ── NO TIMESTAMP, ON PURPOSE ────────────────────────────────────
 *
 * The obvious design is a `projected_at` and "follow rows whose mirror
 * `updated_at` is newer". It loses changes silently: DATETIME has one-second
 * precision, so a mirror refreshed in the same second the projection last ran
 * compares EQUAL and is skipped until something else happens to touch it —
 * which, for a past job, may be never. Candidates are found by comparing the
 * VALUES instead (see `ProjectWorkizRecords::followWorkiz`), which has no clock
 * in it to race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workiz_jobs', function (Blueprint $table) {
            $table->json('projected')->nullable()->after('job_id');
        });

        DB::statement("
            UPDATE workiz_jobs w
            JOIN jobs j ON j.id = w.job_id
            SET w.projected = JSON_OBJECT(
                'status', j.status,
                'scheduled_for', DATE_FORMAT(j.scheduled_for, '%Y-%m-%d %H:%i:%s'),
                'amount_minor', j.amount_minor
            )
            WHERE w.job_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('workiz_jobs', function (Blueprint $table) {
            $table->dropColumn('projected');
        });
    }
};
