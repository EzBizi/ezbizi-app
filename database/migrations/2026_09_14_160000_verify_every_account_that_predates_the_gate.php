<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Confirm every account that existed before there was any way to confirm one.
 *
 * RequireVerifiedEmail went live on 2026-09-14 at 15:00 and refused every write
 * by a user whose `email_verified_at` was null. Twenty-three of the twenty-four
 * accounts on the box were in that state, and none of them could leave it: the
 * column was introduced by the same change that started reading it, and
 * `email_verifications` held zero rows, so no link had ever been sent to
 * anybody. The product was read-only for everyone until it was noticed.
 *
 * That is the shape worth naming rather than the outage. A gate that reads a
 * column nothing had ever populated does not refuse people who ignored an
 * email — it refuses people for whom the email does not exist, and the only
 * exit it offers was built for rows that arrive after it. The question the
 * gate should have been asked on the day it shipped is: which rows are already
 * on the wrong side of this, and what is their way out?
 *
 * ── Why a cutoff rather than "every user" ────────────────────────
 *
 * The gate defends an open front door against scripted signups. Accounts that
 * predate the door are not what it is for, and confirming them costs nothing it
 * was protecting. Accounts created afterwards are exactly what it is for, and
 * a blanket update would switch it off for the only rows that matter — quietly,
 * and for good, because nothing would ever mark them unverified again.
 *
 * The boundary is the release, not the migration's own run time: this may be
 * applied to a database days later, and "now" would then confirm signups the
 * gate was correctly holding.
 *
 * ── Fresh databases ─────────────────────────────────────────────
 *
 * A new database has no users at all, so this writes nothing and is simply a
 * no-op. It exists so that an environment built from migrations matches the one
 * running in production, which an out-of-band UPDATE on the box does not
 * achieve. Production was already repaired by hand on the day; this makes that
 * repair reproducible rather than a fact about one server.
 */
return new class extends Migration
{
    /**
     * When RequireVerifiedEmail began refusing writes. Anything older than this
     * never had a way to confirm; anything newer arrived after the link did.
     */
    private const GATE_LIVE_AT = '2026-09-14 15:00:00';

    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->where('created_at', '<', self::GATE_LIVE_AT)
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Deliberately irreversible.
     *
     * Re-nulling these would put twenty-three accounts back into a state with no
     * exit, which is the defect this exists to repair. A down() that recreates
     * an outage is not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
