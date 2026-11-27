<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * When a campaign turns itself on and off (Jose, 2026-11-05). The days, the
 * on and off times and their timezone; the last edge applied, so the next run
 * acts only when the wanted state changes and a manual flip in between stands.
 */
class AdSchedule extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES): the applier flipping a campaign writes here. */
    protected static string $changeType = 'ad_schedules';

    /** Filed where its ad account's connection is (ChangeFeed). */
    public function changeFeedPlace(): array
    {
        $connection = SocialConnection::withoutGlobalScopes()->find($this->connection_id, ['id', 'scope_type', 'scope_id']);

        return match ($connection?->scope_type) {
            SocialConnection::SCOPE_COMPANY => [$connection->scope_id, null],
            SocialConnection::SCOPE_FRANCHISE => [null, $connection->scope_id],
            default => [null, null],
        };
    }

    use HasUuids;

    public const ON = 'on';

    public const OFF = 'off';

    protected $fillable = [
        'organization_id', 'connection_id', 'object_id', 'object_name', 'days', 'on_at', 'off_at',
        'timezone', 'last_state', 'last_applied_at', 'last_error', 'created_by',
    ];

    protected function casts(): array
    {
        return ['days' => 'array', 'last_applied_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'connection_id');
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            because: 'When a campaign turns itself on and off. Goes with the ad account connection it acts through.',
        );
    }

    /** Whether the campaign should be on at $now: inside a window that began today or, overnight, yesterday. */
    public function wantsOn(Carbon $now): bool
    {
        return collect($this->windows($now->copy()->subDays(1), 2))
            ->contains(fn (array $w) => $now->gte($w[0]) && $now->lt($w[1]));
    }

    /** @return array{at: Carbon, on: bool}|null the next edge after $now that changes the wanted state */
    public function nextChange(Carbon $now): ?array
    {
        $on = $this->wantsOn($now);
        $edges = collect($this->windows($now->copy()->subDays(1), 9))
            ->flatMap(fn (array $w) => [['at' => $w[0], 'on' => true], ['at' => $w[1], 'on' => false]])
            ->filter(fn (array $e) => $e['at']->gt($now))
            ->sortBy(fn (array $e) => $e['at']->getTimestamp())
            ->values();

        // The first edge after which the wanted state differs from now's.
        foreach ($edges as $edge) {
            if ($this->wantsOn($edge['at']) !== $on) {
                return ['at' => $edge['at']->copy()->utc(), 'on' => ! $on];
            }
        }

        return null;
    }

    /** "Mon–Fri 8am–6pm EST", in the schedule's own timezone. */
    public function summary(): string
    {
        $days = collect((array) $this->days)->map(fn ($d) => (int) $d)->unique()->sort()->values();
        $name = fn (int $d) => ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$d];
        $run = $days->count() > 2 && $days->last() - $days->first() === $days->count() - 1;
        $which = match (true) {
            $days->count() === 7 => 'Every day',
            $days->all() === [6, 7] => 'Weekends',
            $run => $name($days->first()).'–'.$name($days->last()),
            default => $days->map($name)->implode(', '),
        };
        $clock = fn (string $hm) => Carbon::parse($hm, $this->timezone)->format(str_ends_with($hm, ':00') ? 'ga' : 'g:ia');

        return $which.' '.$clock($this->on_at).'–'.$clock($this->off_at).' '.Carbon::now($this->timezone)->format('T');
    }

    /** "6:00 PM" today, "Mon 8:00 AM" on another day, in the schedule's timezone. */
    public function when(Carbon $at): string
    {
        $local = $at->copy()->setTimezone($this->timezone);

        return $local->isSameDay(Carbon::now($this->timezone)) ? $local->format('g:i A') : $local->format('D g:i A');
    }

    /** @return list<array{0: Carbon, 1: Carbon}> each listed day's window, from $from's date, for $count days */
    private function windows(Carbon $from, int $count): array
    {
        $windows = [];
        $day = $from->copy()->setTimezone($this->timezone)->startOfDay();

        for ($i = 0; $i < $count; $i++, $day->addDay()) {
            if (! in_array($day->isoWeekday(), array_map('intval', (array) $this->days), true)) {
                continue;
            }

            $start = $day->copy()->setTimeFromTimeString($this->on_at);
            $end = $day->copy()->setTimeFromTimeString($this->off_at);
            $windows[] = [$start, $end->lte($start) ? $end->addDay() : $end];
        }

        return $windows;
    }
}
