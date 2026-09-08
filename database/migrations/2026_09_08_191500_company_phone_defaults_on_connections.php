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
 * ── The wipe ─────────────────────────────────────────────────────
 *
 * Every per-number value except `ring_group_id` is cleared, so the company
 * default is what answers from here. Jose's call, and there are no customers
 * on this system: preserving seven rows nobody has looked at, as explicit
 * overrides of a default they predate, would leave every line quietly
 * exempted from the screen meant to govern it.
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

        // Clear the per-line copies so the company default is what answers.
        // ring_group_id is deliberately left alone: a group claims a specific
        // line, and wiping that would silently unassign every ring group.
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
