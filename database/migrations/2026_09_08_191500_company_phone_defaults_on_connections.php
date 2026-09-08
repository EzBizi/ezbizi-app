<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What answers a call, decided once for the company.
 *
 * ── The complaint ────────────────────────────────────────────────
 *
 * General looked like a company screen and behaved like a per-number editor:
 * it opened with a Line picker and everything under it belonged to whichever
 * line was chosen. So "what happens when somebody rings us" had to be set
 * once per number, seven times over, and the eighth number — the one bought
 * next month — silently answered differently from the other seven.
 *
 * ── Why the connection is the company row ────────────────────────
 *
 * `phone_connections` is already unique on (organization, scope_type,
 * scope_id) and every row in production is a company's, so there is exactly
 * one per company. `require_call_tag` proved the shape a migration ago. A
 * separate settings table keyed the same way would be a join to reach a fact
 * that could sit here.
 *
 * ── A line may still differ, and one of them must ────────────────
 *
 * The number keeps its own columns and they win when set: resolution is
 * `number ?? connection`. That is not hedging — `ring_group_id` is genuinely
 * per line, because Ring groups' "Lines it answers" is how a group claims one
 * number and not another. What changes is that an unset column now inherits
 * instead of meaning "nothing".
 *
 * ── Seed, then clear ─────────────────────────────────────────────
 *
 * The per-number values are lifted into the company default first, and only
 * then cleared — so nothing a company had set stops working the moment this
 * runs. Where the lines DISAGREE the default is left unset, because picking
 * one of two answers somebody chose on purpose would route calls somewhere
 * nobody decided; an unset default is visible on the General screen, a wrong
 * one is not.
 *
 * The first draft cleared without seeding. That read as harmless while the
 * columns looked mostly empty, and was not: on this account all seven lines
 * have `record` on and one carries the only IVR menu in the business, so it
 * would have switched off call recording everywhere and dropped the menu,
 * with a caller the first to know. The suite was 221/221 either way — the
 * code was never the problem.
 */
return new class extends Migration
{
    /** connection column => the number column it stands in for. */
    private const DEFAULTS = [
        'default_flow_id' => 'flow_id',
        'default_hours_schedule_id' => 'hours_schedule_id',
        'default_ring_group_id' => 'ring_group_id',
        'default_voicemail_box_id' => 'voicemail_box_id',
        'default_fallback_number' => 'fallback_number',
        'default_recording_notice' => 'recording_notice',
        'default_after_hours' => 'after_hours',
        'default_after_hours_number' => 'after_hours_number',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('phone_connections')) {
            return;
        }

        Schema::table('phone_connections', function (Blueprint $table) {
            foreach (array_keys(self::DEFAULTS) as $column) {
                $table->string($column, 191)->nullable();
            }

            $table->boolean('default_record')->default(false);
            $table->boolean('default_transcribe')->default(false);
        });

        if (! Schema::hasTable('phone_numbers')) {
            return;
        }

        // SEED FIRST, then clear.
        //
        // The first draft of this nulled the per-number settings and copied
        // nothing, which reads as harmless when the columns are mostly empty
        // and is not. Measured on production before cutting: all seven lines
        // have `record` on, and one carries the only IVR menu in the business.
        // Nulling without seeding would have switched off call recording
        // everywhere — a consent setting, not a convenience — and dropped the
        // menu, and a caller would have been the first to know.
        //
        // Caught by the deploy desk reading the migration against real rows
        // rather than against the test schema. The suite was 221/221 either
        // way: the code was never the problem, the data was.
        foreach (DB::table('phone_connections')->pluck('id') as $connectionId) {
            $lines = DB::table('phone_numbers')
                ->where('connection_id', $connectionId)
                ->get();

            if ($lines->isEmpty()) {
                continue;
            }

            $defaults = [];

            foreach (self::DEFAULTS as $default => $own) {
                // Only where every line that has an opinion agrees.
                //
                // Two lines answering differently is a decision somebody made,
                // and picking one of them to become the company's would route
                // calls somewhere nobody chose. Those stay unset and General
                // says so — an unset default is visible; a wrong one is not.
                $values = $lines->pluck($own)
                    ->reject(fn ($value) => $value === null || $value === ''
                        || $value === 'voicemail')
                    ->unique()
                    ->values();

                if ($values->count() === 1) {
                    $defaults[$default] = $values->first();
                }
            }

            // Booleans the same way, and deliberately NOT "any line had it on".
            // Turning recording on for a line somebody had switched it off for
            // is the one direction that cannot be undone after the fact.
            foreach (['record', 'transcribe'] as $flag) {
                $values = $lines->pluck($flag)->map(fn ($v) => (bool) $v)->unique();

                if ($values->count() === 1) {
                    $defaults['default_'.$flag] = $values->first();
                }
            }

            if ($defaults !== []) {
                DB::table('phone_connections')
                    ->where('id', $connectionId)
                    ->update($defaults);
            }
        }

        // Now clear the per-line copies, so the company default is what
        // answers. ring_group_id is deliberately untouched: a group claims a
        // specific line through "Lines it answers", and wiping that would
        // silently unassign every ring group.
        DB::table('phone_numbers')->update([
            'flow_id' => null,
            'hours_schedule_id' => null,
            'voicemail_box_id' => null,
            'fallback_number' => null,
            'recording_notice' => null,
            'after_hours' => 'voicemail',
            'after_hours_number' => null,
            'record' => false,
            'transcribe' => false,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('phone_connections')) {
            return;
        }

        Schema::table('phone_connections', function (Blueprint $table) {
            $table->dropColumn([
                ...array_keys(self::DEFAULTS),
                'default_record',
                'default_transcribe',
            ]);
        });

        // The cleared per-number values are not restored: they are gone, and
        // inventing them would be worse than the absence.
    }
};
