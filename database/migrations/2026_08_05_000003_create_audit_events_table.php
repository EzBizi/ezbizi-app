<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log (plan §10, review R-11).
 *
 * APPEND-ONLY by construction. There is no updated_at, because a row that can be
 * updated is not an audit record. AuditEvent has no update or delete path, and
 * the application database user should hold only INSERT and SELECT here (see the
 * note in AuditEvent).
 *
 * Written INSIDE the business transaction — the outbox pattern from R-11. If the
 * audit write were queued after commit, a crash between the two loses the record
 * silently; if the business transaction then rolled back, you'd have logged
 * something that never happened. Same transaction means the change and its
 * record are atomic: both, or neither.
 *
 * `delivered_at` is the outbox half. Nothing consumes it yet — the table IS the
 * append-only destination for now — but shipping to an external store later
 * needs no schema change and no redesign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Nullable: platform-plane events (staff, release flags) belong to no
            // tenant. Indexed with occurred_at because "show me this org's recent
            // activity" is the query that will actually be run.
            $table->uuid('organization_id')->nullable();

            // ── who ──
            // 'user'   tenant user
            // 'staff'  EzBizi platform staff (§4.2)
            // 'system' automations, scheduled tasks, migrations
            $table->enum('actor_type', ['user', 'staff', 'system']);
            $table->uuid('actor_id')->nullable();

            // Denormalised on purpose. A foreign key alone loses the answer the
            // moment the user is deleted — and "who did this?" is exactly the
            // question asked about people who have since left.
            $table->string('actor_label')->nullable();

            // ── what ──
            $table->string('action')->index();          // 'company.created'

            $table->string('target_type')->nullable();  // model class
            $table->uuid('target_id')->nullable();
            $table->string('target_label')->nullable(); // denormalised, same reason

            // ── where in the hierarchy ──
            $table->enum('scope_type', ['organization', 'company', 'franchise'])->nullable();
            $table->uuid('scope_id')->nullable();

            // ── detail ──
            // Redacted before it ever reaches here — see AuditLogger::redact().
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();

            // Ties events from one request together, and one event to whatever
            // caused it. Without these, an automation that fires five actions is
            // five unrelated rows and the chain is unreconstructable.
            $table->uuid('correlation_id')->nullable()->index();
            $table->uuid('causation_id')->nullable();

            $table->timestamp('occurred_at');

            // Outbox: null until forwarded to an external store. Unused for now.
            $table->timestamp('delivered_at')->nullable();

            // Deliberately NO updated_at. See the class docblock.

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['actor_type', 'actor_id']);
            $table->index(['target_type', 'target_id']);
            $table->index('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
