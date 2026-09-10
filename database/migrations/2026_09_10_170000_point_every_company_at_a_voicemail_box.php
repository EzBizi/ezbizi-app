<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A company that has a voicemail box is pointed at one.
 *
 * ── Why this needs a backfill and not just the create rule ───────
 *
 * A line's box is `number ?? connection`, so a box nothing points at catches
 * nothing. New boxes have claimed the company default since
 * VoicemailBoxesController::store() learned to — but Jose's A-1 Chimney
 * Mailbox predates that, and a rule that only runs on creation leaves exactly
 * the boxes that reported the bug still catching nothing.
 *
 * Jose's words: it should be set automatically. So the fix is the data, not a
 * screen he has to find.
 *
 * ── The oldest box, and only where nobody has chosen ─────────────
 *
 * Oldest because that is the same rule store() applies — the first box a
 * company makes is the box its calls go to — and applying one rule
 * retroactively is what makes the two agree. A connection that already names
 * a box is left alone; this cannot overwrite a decision.
 *
 * A connection naming a box that has since been DELETED is repointed, because
 * that id resolves to nothing and is indistinguishable from having no box
 * while looking like a setting somebody made.
 */
return new class extends Migration
{
    public function up(): void
    {
        $boxes = DB::table('phone_voicemail_boxes')
            ->select('id', 'connection_id', 'created_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $live = $boxes->pluck('id')->all();

        // The first box seen per connection wins, and the ordering above is
        // what makes "first" mean oldest.
        $oldest = [];
        foreach ($boxes as $box) {
            $oldest[$box->connection_id] ??= $box->id;
        }

        if ($oldest === []) {
            return;
        }

        $connections = DB::table('phone_connections')
            ->select('id', 'default_voicemail_box_id')
            ->whereIn('id', array_keys($oldest))
            ->get();

        foreach ($connections as $connection) {
            $current = (string) ($connection->default_voicemail_box_id ?? '');

            if ($current !== '' && in_array($current, $live, true)) {
                continue;
            }

            DB::table('phone_connections')
                ->where('id', $connection->id)
                ->update(['default_voicemail_box_id' => $oldest[$connection->id]]);
        }
    }

    /**
     * Nothing. This sets a pointer that should have been set when each box was
     * made, and un-setting it would put the messages back where nobody can
     * read them.
     */
    public function down(): void {}
};
