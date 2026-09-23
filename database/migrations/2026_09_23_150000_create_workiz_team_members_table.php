<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workiz team members, mirrored per branch, and the link to an EzBizi user.
 *
 * Jose asked for the link by name: *"for the Team, make sure that we are also
 * able to link the workiz team member to ezbizi accounts as well."* It is what
 * turns `CreatedBy` on an imported job — today a bare string like "Trey
 * Brannan" — into a real person, which is what per-person reporting and any
 * automation about who did the work actually need.
 *
 * ── WORKIZ KEEPS LEAVERS FOR EVER ───────────────────────────────
 *
 * Measured on A-1's live data via A1 Tools, which has been syncing this for
 * months: **444 members, 165 active**. So `is_active` is not decoration — a
 * picker or a match that ignores it offers three leavers for every two people
 * who still work there. A1's own team grid filters on it by default.
 *
 * ── THE LINK IS PROPOSED, NEVER ASSUMED ─────────────────────────
 *
 * All 444 carry an email, so an exact email match is a strong proposal. It is
 * still only a proposal: `auto_matched` records that nobody confirmed it, and
 * A1 — which has had this grid for months — has 60 of 444 linked. Most Workiz
 * members are simply not EzBizi users, and a mirror that guessed would attach
 * work to the wrong person silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workiz_team_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('franchise_id');
            $table->uuid('connection_id')->nullable();

            // Workiz's own user id. Account-scoped, like every other Workiz id.
            $table->string('remote_id');

            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_field_tech')->nullable();
            $table->boolean('is_active')->default(true);

            $table->json('service_areas')->nullable();
            $table->json('skills')->nullable();

            /*
             * The EzBizi person, when somebody has said so.
             *
             * nullOnDelete rather than cascade: erasing a user must not delete
             * the record that Workiz has a member by that name, which is what
             * an imported job's `created_by` is attributed through. §9 erasure
             * anonymises a user's own row rather than deleting it, so this is a
             * database backstop for a path the product does not normally take.
             */
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            // TRUE while the link is only an email match nobody has confirmed.
            $table->boolean('auto_matched')->default(false);

            $table->json('payload');
            $table->dateTime('synced_at');
            $table->timestamps();

            // The franchise, not the connection — a reconnect mints a new
            // connection id, and identity built on a reminted value makes every
            // old row unfindable. Same reasoning as `workiz_jobs`.
            $table->unique(['franchise_id', 'remote_id']);
            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workiz_team_members');
    }
};
