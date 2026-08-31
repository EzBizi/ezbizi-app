<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\PhoneConnection;
use App\Models\PhoneUsageDaily;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Nightly: what each company's phone service cost Twilio yesterday.
 *
 * ── Why nightly, and why it starts now ──────────────────────────
 *
 * EzBizi pays for every tenant's traffic out of one Twilio bill and cannot see
 * what any one company spent. Invoicing onward is out of scope — but you
 * cannot decide to bill later using data you never collected, and Twilio
 * prunes usage records. Every night this does not run is a day nobody can ever
 * get back.
 *
 * ── Why it never throws for one bad account ─────────────────────
 *
 * One suspended subaccount must not stop the other twenty being collected.
 * A connection that fails is counted, reported, and skipped — and the command
 * still exits 0 unless EVERY connection failed, because a scheduler that pages
 * somebody nightly over one dead tenant gets muted, and a muted alarm is worse
 * than none.
 */
class PhoneUsageImport extends Command
{
    protected $signature = 'ezbizi:phone-usage
        {--date= : The day to import, Y-m-d. Defaults to yesterday.}
        {--days=1 : How many days back from --date to cover, for backfill.}';

    protected $description = "Import each phone account's daily Twilio usage";

    public function handle(TwilioGateway $twilio, TenantContext $tenant): int
    {
        // Yesterday, not today: a day still in progress reports partial usage,
        // and the unique key would have us overwrite the complete figure with
        // a smaller one on the next run.
        $end = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::yesterday();

        $days = max(1, (int) $this->option('days'));
        $start = $end->copy()->subDays($days - 1);

        $connections = PhoneConnection::query()
            ->withoutGlobalScopes()
            ->where('status', PhoneConnection::STATUS_CONNECTED)
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No connected phone accounts.');

            return self::SUCCESS;
        }

        $failed = 0;
        $rows = 0;

        foreach ($connections as $connection) {
            try {
                $organization = Organization::query()->findOrFail($connection->organization_id);

                $rows += $tenant->run($organization, fn () => $this->importOne(
                    $twilio,
                    $connection,
                    $start->format('Y-m-d'),
                    $end->format('Y-m-d'),
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->warn("{$connection->id}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            '%s → %s: %d rows from %d accounts, %d failed.',
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $rows,
            $connections->count() - $failed,
            $failed,
        ));

        // Only a total blackout is a failure worth waking somebody for.
        return $failed === $connections->count() ? self::FAILURE : self::SUCCESS;
    }

    private function importOne(
        TwilioGateway $twilio,
        PhoneConnection $connection,
        string $start,
        string $end,
    ): int {
        $written = 0;

        foreach ($twilio->usageDaily($connection, $start, $end) as $record) {
            $day = $record['start_date'] ?? null;

            // A record with no date cannot be filed against a day, and
            // guessing one would put somebody's cost in the wrong month.
            if (! is_string($day) || $day === '') {
                continue;
            }

            PhoneUsageDaily::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'day' => $day,
                    'category' => $record['category'],
                ],
                [
                    'organization_id' => $connection->organization_id,
                    'description' => $record['description'] ?? null,
                    'usage' => $record['usage'] ?? '0',
                    'usage_unit' => $record['usage_unit'] ?? null,
                    'count' => (int) ($record['count'] ?? 0),
                    'count_unit' => $record['count_unit'] ?? null,
                    'price' => $record['price'] ?? '0',
                    'price_unit' => $record['price_unit'] ?? 'USD',
                ],
            );

            $written++;
        }

        return $written;
    }
}
