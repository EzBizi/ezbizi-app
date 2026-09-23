<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bookable window on one weekday in one service area, and how many jobs fit
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md §4).
 *
 * A row is per (area, weekday), so `max_jobs` sits here rather than in a table
 * of its own — the slot already knows its area and its day.
 *
 * A weekday with no rows is a CLOSED day. Absence already means it, so there is
 * no flag to keep in step with the rows.
 *
 * ── CHANGING THE GRAIN BREAKS A READER SILENTLY (2026-09-23) ─────
 *
 * The grain is (area, WEEKDAY). Reports derives two columns from it —
 * `ServiceAreaShapeReportTest`, commit 28b553bf — with correlated subqueries
 * that count distinct weekdays, and Jose's automations warn by email and SMS
 * off the back of them.
 *
 * Move this to a row per DATE, which is what spec 3.4 (per-date overrides,
 * "closed on the 18th") would do, and `count(distinct weekday)` still returns
 * a number. It just stops meaning "days a week". Nothing errors, no test reds,
 * and an alert that was true keeps being sent with a different meaning.
 *
 * So: a change to this table's grain is a change to somebody else's report.
 * Tell the Reports session before it lands — they asked to be told, and they
 * are the ones who cannot see it happen.
 *
 * THERE IS A TRIPWIRE, AND GOING GREEN IS THE WRONG FIX.
 * `test_a_slot_is_still_a_weekly_pattern` (Reports, 69758c62) reds when this
 * table gains a date dimension or loses `weekday`. It is not protecting this
 * table — it is protecting two columns and a live alert that would otherwise
 * change meaning without a symptom. When it fires, their columns get rewritten
 * against the new grain; nobody edits the test to make it pass.
 *
 * Do not let it make you cautious about 3.4. That is what a tripwire is for.
 */
#[Fillable([
    'organization_id', 'service_area_id', 'weekday', 'start_time', 'end_time',
    'max_jobs',
])]
class ServiceAreaSlot extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'When a service area works and how much it can take. Configuration of '
            .'the business, not a record of a day, so it does not accumulate: '
            .'editing hours changes these rows rather than adding to them.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'max_jobs' => 'integer',
        ];
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }
}
