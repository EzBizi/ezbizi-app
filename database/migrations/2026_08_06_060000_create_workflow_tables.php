<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The automation engine (plan §5.5).
 *
 * "A first-class subsystem, not a feature bolted on later. It is the main
 * expression of the no-hardcoding rule (§2.1)."
 *
 * Four tables, and the separation between them is the design:
 *
 *   workflows          what exists, and which version is live
 *   workflow_versions  IMMUTABLE definitions — editing makes a new one
 *   workflow_events    the transactional outbox
 *   workflow_runs      what actually happened, once per (event, version)
 *
 * ── The names here are historical ────────────────────────────────
 *
 * The feature was called "workflows" until 2026-08-10, when it was renamed to
 * "automations" everywhere — tables, columns, classes and permission keys. See
 * `2026_08_10_000000_rename_workflows_to_automations`. This migration keeps the
 * old names because it has already run against every database that exists;
 * editing it would only make it disagree with what it actually did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // Organization by default; a Company or Franchise may own its own
            // (§5.5 scope). Null scope means the whole organization.
            $table->string('scope_type', 20)->nullable();
            $table->uuid('scope_id')->nullable();

            $table->string('name');
            $table->text('description')->nullable();

            // Which registered trigger starts it. Validated against the module
            // registry, so a workflow can never listen for an event no module
            // emits — that would look configured and never fire.
            $table->string('trigger_key', 100);

            $table->boolean('is_active')->default(false);
            $table->uuid('active_version_id')->nullable();

            // Auto-disabled after repeated failure (§5.5 operability). Kept
            // distinct from is_active so re-enabling is a deliberate act and the
            // reason survives.
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);

            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'trigger_key', 'is_active']);
        });

        /*
         * Versions are append-only.
         *
         * §5.5: "Workflow versioning with activation and rollback — an edit must
         * not rewrite history." A run records the version it executed, so
         * "why did this fire in March" stays answerable after somebody changes
         * the rule in April. Editing in place would make every historical run
         * a description of a workflow that no longer exists.
         */
        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('workflow_id');
            $table->unsignedInteger('version');

            // Nested AND/OR tree. Empty means "always" — a trigger with no
            // conditions is legitimate and common.
            $table->json('conditions')->nullable();
            $table->json('actions');

            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            // No updated_at, deliberately: a version that can be edited is not a
            // version.

            $table->foreign('workflow_id')->references('id')->on('workflows')->cascadeOnDelete();
            $table->unique(['workflow_id', 'version']);
        });

        /*
         * The transactional outbox (§5.5, §10).
         *
         * Written in the SAME transaction as the business change. If the change
         * rolls back so does the event; if it commits, the event is already
         * durable and delivery is somebody else's problem. Dispatching to a queue
         * from inside the transaction instead is the classic bug: the job can be
         * picked up before the commit lands, or the commit can fail after the job
         * was queued, and the two states disagree.
         */
        Schema::create('workflow_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->string('event_key', 100);
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();

            // before/after for field-change conditions, plus whatever the emitter
            // considered relevant. Redacted through AuditLogger's rules.
            $table->json('payload')->nullable();

            $table->uuid('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            // Loop protection (§5.5): depth alone does not stop A→B→A across
            // separate delayed runs, so the causation chain is carried too and
            // the whole cascade shares one budget.
            $table->uuid('root_event_id')->nullable();
            $table->uuid('causation_id')->nullable();
            $table->unsignedTinyInteger('depth')->default(0);

            $table->string('correlation_id', 36)->nullable();

            $table->timestamp('occurred_at');
            // When it becomes eligible. Scheduled triggers write a future one.
            $table->timestamp('available_at');
            $table->timestamp('delivered_at')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            // Permanent failures land here rather than retrying forever (§5.5
            // dead-letter). Kept in the same table so nothing is lost.
            $table->timestamp('failed_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            // The dispatcher's query: undelivered, unfailed, due.
            $table->index(['delivered_at', 'failed_at', 'available_at']);
            $table->index(['organization_id', 'event_key', 'occurred_at']);
            $table->index('root_event_id');
        });

        /*
         * One row per (event, workflow version).
         *
         * The unique index IS the idempotency guarantee (§5.5 at-least-once with
         * idempotency keys). A redelivered event cannot run the same version
         * twice, so a retry after a partial failure cannot send the email again.
         */
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('workflow_id');
            $table->uuid('workflow_version_id');
            $table->uuid('event_id');

            // matched / skipped / succeeded / failed
            $table->string('status', 20);

            // What the conditions actually evaluated to, and what each action
            // did. §5.5 operability: "Every run audited: trigger, conditions
            // evaluated, actions taken, outcome."
            $table->json('condition_trace')->nullable();
            $table->json('action_results')->nullable();
            $table->text('error')->nullable();

            // Set when the run was a rehearsal (§5.5 test mode). A test run
            // performs no side effects and says so.
            $table->boolean('is_test')->default(false);

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('workflow_id')->references('id')->on('workflows')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('workflow_events')->cascadeOnDelete();

            $table->unique(['event_id', 'workflow_version_id'], 'workflow_runs_idempotency');
            $table->index(['organization_id', 'workflow_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
    }
};
