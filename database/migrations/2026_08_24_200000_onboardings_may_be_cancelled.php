<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An onboarding that was started by mistake has to be able to stop.
 *
 * The row stays — same argument as withdrawing an invitation. Deleting it
 * would erase that paperwork was begun, and a signed agreement whose
 * paperwork can vanish is one nobody can produce. `cancelled_at` is the
 * act; `status` is already a stored column because these transitions are
 * acts, not the clock (see the onboardings migration).
 *
 * UNIQUE on invitation_id made that stop a dead end: a cancelled start
 * still occupied the invitation, so the only way to take somebody on
 * under a different contract was a second invitation. The uniqueness
 * that still matters — one LIVE agreement per arrival — is enforced in
 * HrOnboardingsController, where cancelled rows do not count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');

            // MySQL is using the unique index to enforce the foreign key, so
            // the unique cannot be dropped while the FK is still on it.
            $table->dropForeign(['invitation_id']);
        });

        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropUnique(['invitation_id']);
            $table->index('invitation_id');
            $table->foreign('invitation_id')
                ->references('id')->on('invitations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropForeign(['invitation_id']);
        });

        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropIndex(['invitation_id']);
            $table->unique('invitation_id');
            $table->foreign('invitation_id')
                ->references('id')->on('invitations')->nullOnDelete();
            $table->dropColumn('cancelled_at');
        });
    }
};
