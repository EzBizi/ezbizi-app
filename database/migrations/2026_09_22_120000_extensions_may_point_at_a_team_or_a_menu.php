<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An extension may name a team or a menu, not only a person.
 *
 * ── What Jose asked for ──────────────────────────────────────────
 *
 * 2026-09-17: *"On Settings i want a new tab called Extensions, so i can
 * assign extensions to users, ivr menus, ring groups"*.
 *
 * Two thirds of that already exist: `phone_extensions` has held people since
 * August and eleven are assigned in production. What it cannot express is an
 * extension that reaches a TEAM or plays a MENU, because `user_id` is the only
 * target and it is NOT NULL.
 *
 * ── Why three nullable columns and no discriminator ─────────────
 *
 * This deliberately does NOT copy the shape of `phone_numbers.answer_with`,
 * and the difference is worth stating so nobody "fixes" the inconsistency.
 *
 * There, a discriminator carried information no column could: `voicemail` and
 * `self` are destinations with no id to point at, so without a word for them
 * the setting could not be expressed at all.
 *
 * Here every kind of target HAS an id. So the kind is DERIVED from whichever
 * column is filled, and a discriminator would be a fourth thing to keep in
 * step with the other three — the drift this module keeps removing. Exactly
 * one of the three is set; `PhoneExtension::target()` is the single place that
 * reads them.
 *
 * ── Why the foreign key is dropped and put back ─────────────────
 *
 * MySQL will not alter a column an FK constrains. The constraint is restored
 * with the same rule it had — `cascadeOnDelete`, so deleting a person still
 * takes their extension with them.
 *
 * ── What the unique indexes already do, and why that still holds ─
 *
 * `unique(connection_id, extension)` is what makes "call 101" unambiguous and
 * it is untouched — a team extension collides with a person's exactly as two
 * people's would, which is right.
 *
 * `unique(connection_id, user_id)` now admits many rows with a NULL user,
 * because MySQL does not treat NULLs as equal in a unique index. That is the
 * behaviour wanted: one extension per person, and as many team and menu
 * extensions as somebody cares to make.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_extensions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        DB::statement('ALTER TABLE phone_extensions MODIFY user_id CHAR(36) NULL');

        Schema::table('phone_extensions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->foreignUuid('ring_group_id')
                ->nullable()
                ->after('user_id')
                ->constrained('phone_ring_groups')
                ->nullOnDelete();

            // nullOnDelete rather than cascade, and the two differ for a
            // reason. Deleting a PERSON should take their extension with them:
            // the digits meant that person. Deleting the team or menu an
            // extension points at leaves the digits published — somebody has
            // told customers to dial 300 — so the row survives pointing at
            // nothing, which the Extensions screen can show as needing a new
            // destination rather than silently vanishing.
            $table->foreignUuid('flow_id')
                ->nullable()
                ->after('ring_group_id')
                ->constrained('phone_flows')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('phone_extensions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ring_group_id');
            $table->dropConstrainedForeignId('flow_id');
        });

        // Rows with no person cannot survive the column going back to NOT
        // NULL, and they are exactly the rows this migration made possible.
        DB::table('phone_extensions')->whereNull('user_id')->delete();

        Schema::table('phone_extensions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        DB::statement('ALTER TABLE phone_extensions MODIFY user_id CHAR(36) NOT NULL');

        Schema::table('phone_extensions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
