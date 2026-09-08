<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The marker `ezbizi:expire-training-passes` (task 10) checks, and why the
 * outbox could not be the marker instead (review round 1).
 *
 * The command originally treated an existing `training.expired` row in
 * `automation_events` as the fact "this lapse was already announced" — the
 * outbox as its own idempotency marker, on the reasoning that a second column
 * would be a second copy of a fact the outbox already held. That reasoning
 * assumed the outbox row survives, and it does not:
 * `AutomationEvent::retention()` is `RetentionPolicy::window(3, 'occurred_at',
 * ...)`, and `ExpireData::expirable()` hard-deletes every DELIVERED or
 * DEAD-LETTERED row past that window. An event this sweep raises always
 * becomes one of those within a minute — `ezbizi:dispatch-automation-events`
 * runs every minute. Proven live in review: emit, delete the row (standing in
 * for the 3-month sweep), re-run the command — a SECOND `training.expired`
 * landed for the same attempt.
 *
 * The timing makes it worse than a theoretical gap. `routes/console.php` runs
 * `ezbizi:expire-data` at 03:50 and this sweep at 04:10, so a lapsed pass's
 * outbox row is delivered within a minute of being raised, pruned at 03:50 on
 * day+90, and re-announced twenty minutes later the same night — every three
 * months, forever, for anybody who lapsed and never retook. A tenant rule
 * "re-assign the ladder guide when the pass lapses" becomes a quarterly
 * re-assignment carrying an `expires_on` months in the past.
 *
 * The outbox is DELIBERATELY temporary (that is what retention on it means);
 * a fact that has to outlive it needs a column that does. `expiry_announced_at`
 * is that column: set once, in the same transaction as the `emit()` call that
 * raises the event, and never cleared. It also gives the candidate query a
 * lower bound it never had before — without it, the very first run of the
 * command announces every lapse in the table's entire history in one go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_attempts', function (Blueprint $table) {
            $table->timestamp('expiry_announced_at')->nullable()->after('expires_on');

            /*
             * Built for the sweep's actual query, not for the migration's
             * original `(organization_id, expires_on)` index. The sweep runs
             * `TenantContext::runWithoutTenant()` — genuinely cross-tenant, by
             * design — so there is no `organization_id` predicate for that
             * index's leading column to serve; `EXPLAIN` on the unindexed
             * query showed `type: ALL, possible_keys: null` (a full table
             * scan) as a direct result. `passed = 1` and `expiry_announced_at
             * IS NULL` are both equality predicates the query always carries;
             * `expires_on <= ?` is the range. Leading with the two equalities
             * lets MySQL serve all three predicates from one index rather than
             * scanning the whole table and filtering.
             */
            $table->index(['passed', 'expiry_announced_at', 'expires_on'],
                'training_attempts_unannounced_lapse_index');
        });
    }

    public function down(): void
    {
        Schema::table('training_attempts', function (Blueprint $table) {
            $table->dropIndex('training_attempts_unannounced_lapse_index');
            $table->dropColumn('expiry_announced_at');
        });
    }
};
