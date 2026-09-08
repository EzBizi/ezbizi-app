<?php

namespace App\Console\Commands;

use App\Models\TimeClockShift;
use App\Support\Tenancy\TenantContext;
use App\Support\TimeClock\TimeClock;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Clock out anybody whose scheduled end has passed (design 2026-09-08 §8).
 *
 * A sweep, not a delayed job, for the reason the social publisher gives: the
 * row IS the state. Extend moves `scheduled_end_at` and the row simply stops
 * being due. Goes through TimeClock::clockOut so it is audited, raised and
 * listed exactly like a person's punch, with `ended_by = schedule`.
 *
 * Without tenant scope: the scheduler is nobody. Each close re-enters the
 * shift's own organization, which is where the boundary lives.
 *
 * ponytail: no chunking. Due rows are "people whose day just ended this
 * minute"; if that ever exceeds a sweep, `->chunkById()` on the same query.
 */
class CloseEndedShifts extends Command
{
    protected $signature = 'ezbizi:close-ended-shifts';

    protected $description = 'Clock out open shifts whose scheduled end has passed';

    public function __construct(
        private readonly TimeClock $timeClock,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // The eager load reads users and organizations across every tenant,
        // which the read guard refuses unless the sweep says so explicitly.
        $due = $this->tenant->runWithoutTenant(fn () => TimeClockShift::query()
            ->withoutGlobalScopes()
            ->open()
            ->whereNotNull('scheduled_end_at')
            ->where('scheduled_end_at', '<=', now())
            ->with('user.organization')
            ->get());

        $closed = 0;

        foreach ($due as $shift) {
            $user = $shift->user;

            if ($user === null || $user->organization === null) {
                continue;
            }

            try {
                $this->tenant->run($user->organization, fn () => $this->timeClock->clockOut(
                    $user,
                    endedBy: TimeClockShift::ENDED_BY_SCHEDULE,
                ));
                $closed++;
            } catch (ValidationException) {
                // Closed by the person between the query and the lock. Not a fault.
            }
        }

        if ($closed > 0) {
            $this->info("Closed {$closed} shift(s) past their scheduled end.");
        }

        return self::SUCCESS;
    }
}
