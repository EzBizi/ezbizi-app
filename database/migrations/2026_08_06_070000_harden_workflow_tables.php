<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the adversarial review of the automation engine found (plan §5.5, §5.1).
 *
 * The `workflow_*` names below are historical: the feature was renamed to
 * "automations" on 2026-08-10, and the constraints created here were renamed
 * with their tables — see
 * `2026_08_10_000000_rename_workflows_to_automations`. This migration is left
 * describing what it actually did.
 *
 * Three things, all of which the first migration should have had:
 *
 *   action_count   the cascade budget counted RUNS, so a budget of 50 was 50
 *                  rules firing — each carrying any number of actions. The real
 *                  ceiling on outbound calls was unbounded.
 *
 *   queued_at      the sweep re-dispatched every in-flight event once a minute,
 *                  which also defeated the widening retry backoff entirely.
 *
 *   composite FKs  the customers/jobs migration written the day before enforces
 *                  "the parent must be in the same organization" at the storage
 *                  layer. These tables did not, so the database would have
 *                  accepted a run pointing at another tenant's workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('action_count')->default(0)->after('action_results');
        });

        Schema::table('workflow_events', function (Blueprint $table) {
            // A lease, not a flag. The sweep claims an event for a while rather
            // than marking it queued forever, so a job lost to a worker crash is
            // eventually retried instead of stranded.
            $table->timestamp('queued_at')->nullable()->after('available_at');
            $table->index(['queued_at', 'delivered_at']);
        });

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;   // SQLite cannot add constraints after the fact
        }

        Schema::table('workflows', function (Blueprint $table) {
            $table->unique(['organization_id', 'id'], 'workflows_org_id_unique');
        });

        Schema::table('workflow_events', function (Blueprint $table) {
            $table->unique(['organization_id', 'id'], 'workflow_events_org_id_unique');
        });

        Schema::table('workflow_versions', function (Blueprint $table) {
            $table->foreign(['organization_id', 'workflow_id'], 'workflow_versions_same_org')
                ->references(['organization_id', 'id'])->on('workflows')->cascadeOnDelete();
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->foreign(['organization_id', 'workflow_id'], 'workflow_runs_workflow_same_org')
                ->references(['organization_id', 'id'])->on('workflows')->cascadeOnDelete();

            $table->foreign(['organization_id', 'event_id'], 'workflow_runs_event_same_org')
                ->references(['organization_id', 'id'])->on('workflow_events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('workflow_runs', function (Blueprint $table) {
                $table->dropForeign('workflow_runs_workflow_same_org');
                $table->dropForeign('workflow_runs_event_same_org');
            });

            Schema::table('workflow_versions', function (Blueprint $table) {
                $table->dropForeign('workflow_versions_same_org');
            });

            Schema::table('workflow_events', function (Blueprint $table) {
                $table->dropUnique('workflow_events_org_id_unique');
            });

            Schema::table('workflows', function (Blueprint $table) {
                $table->dropUnique('workflows_org_id_unique');
            });
        }

        Schema::table('workflow_events', function (Blueprint $table) {
            $table->dropIndex(['queued_at', 'delivered_at']);
            $table->dropColumn('queued_at');
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropColumn('action_count');
        });
    }
};
