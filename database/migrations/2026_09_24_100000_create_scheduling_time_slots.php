<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The time windows a business books into — "10 AM – 1 PM", "1 – 3", "3 – 6".
 *
 * Jose, 2026-09-24: "go ahead and add the Time Slots option". Until now there
 * was no way to state a window at all: opening a day in the Settings editor
 * wrote a hard-coded 08:00–12:00, which is why every one of A-1's 108 capacity
 * rows is that window.
 *
 * ── Defined ONCE per organization, not per area ─────────────────
 *
 * A1 Tools states its windows once per company and every service area uses the
 * same ones (docs/superpowers/specs/2026-09-23-a1tools-time-slots-how-they-work.md).
 * Per-area windows would be 34 areas × 7 days × 3 windows = 714 rows typed by
 * hand for one pattern. So the WINDOWS live here, and the per-area part — how
 * many jobs each window takes on each day — stays in `service_area_slots`.
 *
 * ── Why `service_area_slots` keeps its own start and end ────────
 *
 * Every reader of capacity (availability, the dashboard glance, SlotShape)
 * reads the window off the capacity row. Replacing those columns with a foreign
 * key would move all of them at once. Instead the rows COPY the window from
 * here, and SchedulingTimeSlotsController moves every matching row in the same
 * transaction when a window's hours change, or deletes them when it goes.
 *
 * ── Backfill ────────────────────────────────────────────────────
 *
 * Every window already in use becomes a slot for its organization, so no
 * existing capacity row is left pointing at a window the catalog does not
 * list. For A-1 that is one: 08:00–12:00. Change it in Settings and all 108
 * rows follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_time_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'start_time']);
        });

        $windows = DB::table('service_area_slots')
            ->join('service_areas', 'service_areas.id', '=', 'service_area_slots.service_area_id')
            ->select('service_areas.organization_id', 'service_area_slots.start_time', 'service_area_slots.end_time')
            ->distinct()
            ->get();

        $now = now();

        foreach ($windows as $w) {
            // insertOrIgnore: two windows sharing a start time in one
            // organization would collide on the unique index. The first wins;
            // the rows of the second stay as they are and still read correctly,
            // they just are not offered as a choice until somebody adds them.
            DB::table('scheduling_time_slots')->insertOrIgnore([
                'id' => (string) Str::orderedUuid(),
                'organization_id' => $w->organization_id,
                'start_time' => $w->start_time,
                'end_time' => $w->end_time,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_time_slots');
    }
};
