<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations — the first way anybody other than the founder gets an account.
 *
 * Reference: EZBIZI-HR-PLAN.md ("Invitations"), and §4 of EZBIZI-BUILD-PLAN.md
 * for every rule the shape below is trying to make true.
 *
 * ── Why the invitation carries the ROLE and the SCOPE ────────────
 *
 * The owner's decision: the inviter chooses both at the moment of inviting, so
 * the link is complete and the person lands somewhere real. That is why this
 * table has a `role_id` and an assignment target rather than deferring both to
 * an administrator who has to remember afterwards — an account with no role and
 * no assignment signs in perfectly and can do nothing, with nothing on screen
 * saying why (the same failure RolesController::destroy refuses to create).
 *
 * ── The scope columns are the assignment's, deliberately ─────────
 *
 * Three nullable columns with the same XOR CHECK as `assignments`, not a
 * `scope_type`/`scope_id` pair. Two reasons, and the first is the one that
 * matters: a polymorphic pair has no foreign key, so an invitation could name a
 * franchise that has since been deleted — or one belonging to another
 * organization — and nothing in the database would object. The second is that
 * acceptance copies these three columns straight into an `assignments` row, so
 * the two tables agreeing on their shape means there is no translation step to
 * get wrong. The API still speaks scope_type/scope_id, because one field pair is
 * a better wire contract than three columns of which exactly one is set.
 *
 * ── No `status` column ───────────────────────────────────────────
 *
 * Status is DERIVED — see App\Models\Invitation::status(). The plan's
 * "abandoned" is what an unaccepted invitation becomes when its week runs out,
 * and a stored column would need a scheduled job to keep it true. A job that
 * stops running leaves every expired invitation showing as "sent", which is a
 * lie about a credential's lifetime. The timestamps below cannot go stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')
                  ->constrained()->cascadeOnDelete();

            $table->string('email');

            // Pre-filled by the inviter, or left for the person to complete —
            // the plan is explicit that either is fine. The rest of the common
            // core (phone, date of birth, gender) is asked at acceptance rather
            // than at invitation, because the inviter usually does not know it.
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();

            /*
             * nullOnDelete, and the open-invitation case refused above it.
             *
             * A role deleted years later must not take the historical record of
             * who was invited with it — that is audit-relevant, and cascading
             * would delete it silently. But an OPEN invitation whose role
             * evaporates would create exactly the roleless account described at
             * the top of this file, so RolesController::destroy refuses while
             * one is outstanding, in the same breath as it refuses a role people
             * still hold.
             */
            $table->foreignUuid('role_id')->nullable()
                  ->constrained('roles')->nullOnDelete();

            // Who to name on the acceptance screen. Nullable because the inviter
            // may have left the organization by the time the link is opened, and
            // that must not break the link.
            $table->foreignUuid('invited_by_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();

            // Exactly one of these three, enforced by the CHECK below — the same
            // invariant, and the same reasoning, as `assignments`.
            $table->foreignUuid('assigned_organization_id')->nullable()
                  ->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('assigned_company_id')->nullable()
                  ->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('assigned_franchise_id')->nullable()
                  ->constrained('franchises')->cascadeOnDelete();

            /*
             * The credential, hashed. The raw token exists for exactly as long
             * as it takes to build the response that carries it.
             *
             * sha256 rather than bcrypt for the same reason password_resets uses
             * it: this is a 48-character random string, so there is nothing to
             * slow a guesser down about, and the public acceptance endpoint has
             * to look one up by value — which bcrypt cannot do without hashing
             * against every row.
             *
             * UNIQUE, and unique GLOBALLY rather than per organization: the
             * lookup happens before any tenant exists, so the token is the only
             * thing that names one. Two tenants holding the same hash would make
             * that lookup ambiguous, which is the one thing it may never be.
             */
            $table->char('token_hash', 64)->unique();

            // Moved forward by a resend, which is what "the clock restarts"
            // means. Separate from created_at so "invited on the 3rd, chased on
            // the 11th" stays legible.
            $table->timestamp('sent_at');
            $table->timestamp('expires_at');

            // The link was opened — the plan's "received" status. Cleared by a
            // resend, because the link they opened is not the live one any more.
            $table->timestamp('opened_at')->nullable();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // The account acceptance produced. The join that answers "where did
            // this person come from" without guessing by email.
            $table->foreignUuid('accepted_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The list screen's query: this organization's invitations, and
            // "has this address already been invited here".
            $table->index(['organization_id', 'email']);
        });

        // Driver-aware for the same reason assignments' constraint is: SQLite
        // cannot ALTER TABLE ADD CONSTRAINT, tests run against MySQL, and
        // Invitation::saving mirrors the rule so the message is a readable
        // exception rather than SQL error 3819.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                ALTER TABLE invitations ADD CONSTRAINT invitations_exactly_one_scope CHECK (
                    (assigned_organization_id IS NOT NULL)
                  + (assigned_company_id      IS NOT NULL)
                  + (assigned_franchise_id    IS NOT NULL) = 1
                )
            ');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
