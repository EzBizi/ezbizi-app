<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An inbox can be given to a PERSON, not only to a role.
 *
 * Jose, 2026-10-06: "I also want to be able to assign emails to specific
 * people, instead of just roles -- maybe done via the email settings tab".
 *
 * A grant row now names exactly one of: a role (as before, written by the
 * roles editor) or a person (new, written from Email settings). Both through
 * App\Support\Access\InboxAccess, which stays the one class that writes this
 * table.
 *
 * A person grant replaces only the GRANT half of MailboxAccess. They still
 * need the Email module and Inbox tab through their role, and the inbox must
 * still be within their reach. A grant is a key to one door, not a way round
 * the building's other locks.
 *
 * `user_id` cascades on delete, like `role_id`: a person removed takes their
 * grants with them, and a stale row naming nobody can never become access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_grants', function (Blueprint $table) {
            $table->uuid('role_id')->nullable()->change();
            $table->uuid('user_id')->nullable()->after('role_id');

            // Giving a person the same inbox twice is giving it once -- the same
            // reason (mailbox_id, role_id) is unique.
            $table->unique(['mailbox_id', 'user_id']);
            $table->index(['organization_id', 'user_id']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Person grants have no role, so they cannot survive role_id becoming
        // required again. Rolling back removes them -- they did not exist
        // before this migration, so nothing older is lost.
        DB::table('mailbox_grants')->whereNull('role_id')->delete();

        Schema::table('mailbox_grants', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['mailbox_id', 'user_id']);
            $table->dropIndex(['organization_id', 'user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('mailbox_grants', function (Blueprint $table) {
            $table->uuid('role_id')->nullable(false)->change();
        });
    }
};
