<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ViewTimezone;
use App\Support\Workiz\WorkizLeadAttributes;
use App\Support\Workiz\WorkizLeads;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * A-1's Workiz book, from now on.
 *
 * ── Two rulings from Jose shape every decision in this file ──────
 *
 * **"we don't need the past stuff, just start from now"** (2026-09-23). So
 * there is no backfill. A branch imports what was made from that day, or from
 * when it connected if later (STARTED); nothing before is asked for. A-1's thirty-one thousand historical rows stay in Workiz, where
 * they already are.
 *
 * **"whatever you build must not put us in a dependency state."** Nothing here
 * touches A1 Leads or Rodrigo's host. Workiz is EzBizi's own connection — a
 * branch's API token in `social_connections` — so when his system is removed
 * this command is unaffected. The rows it writes are ours, in our table, and
 * survive every other system being switched off.
 *
 * ── Why some rows are deliberately not imported ──────────────────
 *
 * About half of A-1's Workiz volume originates from Google Local Services and
 * Thumbtack — channels EzBizi already ingests directly, with a real thread, the
 * call recording and the ability to reply. Importing those would put the same
 * member of the public in the list twice, and the unique key cannot catch it
 * because the two systems issue different ids.
 *
 * So those are skipped and everything else is kept. The list is an EXCLUSION
 * rather than an inclusion, and that direction is the decision: a channel A-1
 * adds next month arrives as an unknown `JobSource` and is IMPORTED. Missing a
 * real enquiry is worse than showing a duplicate, and an inclusion list would
 * silently drop every new channel until somebody noticed.
 */
class ImportWorkizLeads extends Command
{
    /** Jose, 2026-09-23: "just start from now". */
    public const STARTED = '2026-09-23 00:00:00';

    protected $signature = 'ezbizi:import-workiz-leads
                            {--connection= : one connection id, rather than every connected branch}
                            {--limit=1000 : how many rows to ask Workiz for per branch}';

    protected $description = 'Import new Workiz leads for every connected branch.';

    /**
     * Channels EzBizi already ingests live, normalised for comparison.
     *
     * `JobSource` is free text and already dirty in A-1's own data — `GMB /SEO`
     * and `GMB / SEO` are the same channel with different whitespace — so the
     * comparison lowercases and collapses everything that is not a letter or a
     * digit. An exact-string match would have let one spelling through.
     */
    // leads.workiz_skip_sources (Jose, 2026-11-04: no business values in code).

    public function __construct(private readonly TenantContext $tenant)
    {
        parent::__construct();
    }

    public function handle(WorkizLeads $reader): int
    {
        /*
         * Deliberately unscoped, then re-scoped per organization below.
         *
         * A scheduled command has no tenant in context and the guard is right
         * to refuse an implicit cross-tenant read - it says so in as many
         * words. Spanning tenants is this command's job, so it says so too,
         * and every WRITE happens inside that organization's context.
         */
        $connections = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No Workiz connections.');

            return self::SUCCESS;
        }

        $imported = 0;
        $skipped = 0;

        $organizations = Organization::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())
            ->get()
            ->keyBy(fn (Organization $o) => $o->getKey());

        foreach ($connections as $connection) {
            $organization = $organizations->get($connection->organization_id);

            if ($organization === null) {
                continue;
            }

            [$new, $dropped] = $this->tenant->run(
                $organization,
                fn () => $this->importBranch($reader, $connection, $organization),
            );

            $imported += $new;
            $skipped += $dropped;
        }

        $this->info("Imported {$imported} lead(s); skipped {$skipped} already ingested elsewhere.");

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} */
    private function importBranch(
        WorkizLeads $reader,
        SocialConnection $connection,
        Organization $organization,
    ): array {
        /*
         * THE WATERMARK, AND WHY IT IS DERIVED RATHER THAN STORED.
         *
         * The newest Workiz lead already held for this branch is the high-water
         * mark. No new column, no state to get out of step with the rows it
         * describes, and re-running is harmless because the upsert is on the
         * unique key.
         *
         * With no rows yet, Jose's "start from now" is the watermark: STARTED,
         * or when the branch connected, so nothing historical is asked for at
         * all. Not a filter applied after fetching
         * thirty-one thousand rows — they are never requested.
         */
        $watermark = Lead::query()
            ->where('connection_id', $connection->getKey())
            ->where('source', Lead::SOURCE_WORKIZ)
            ->max('received_at');

        // "Now" is when Jose said it (2026-09-23), or when the branch connected
        // if later. Each run's own now moved every minute, so a branch with no
        // lead yet skipped every lead as older than the run (New Jersey's
        // 2026-10-07 enquiry, found 2026-10-08).
        $since = $watermark !== null ? Carbon::parse($watermark)
            : Carbon::parse(self::STARTED, 'UTC')->max($connection->created_at ?? now());

        $result = $reader->raw(
            $connection,
            (int) $this->option('limit'),
            $since->toDateString(),
        );

        if ($result['failed'] !== null) {
            /*
             * Reported and not thrown: nine branches are read in one run and one
             * unreachable token must not stop the other eight. The lead sync
             * learned this the hard way in September.
             */
            Log::warning('Workiz lead import could not read a branch.', [
                'connection_id' => $connection->getKey(),
                'because' => $result['failed'],
            ]);

            $this->warn("  {$connection->display_name}: {$result['failed']}");

            return [0, 0];
        }

        $franchise = $connection->scope_type === SocialConnection::SCOPE_FRANCHISE
            ? Franchise::find($connection->scope_id)
            : null;

        $timezone = ViewTimezone::forFranchise($franchise, $organization);

        $imported = 0;
        $skipped = 0;

        foreach ($result['leads'] as $payload) {
            if ($this->alreadyOurs($payload)) {
                $skipped++;

                continue;
            }

            $attributes = WorkizLeadAttributes::fromPayload($payload);

            if (($attributes['remote_id'] ?? '') === '') {
                // No stable id means no unique key, and a row that cannot
                // de-duplicate would multiply on every run.
                $skipped++;

                continue;
            }

            /*
             * The naive local string becomes an instant HERE, using the branch's
             * zone, because this is the only place both facts are in scope.
             * Workiz sends `Y-m-d H:i:s` with no offset and never sends
             * `Timezone`; written straight into a UTC column it lands hours out,
             * which is how the Local Services stamps went wrong in August.
             */
            $receivedAt = $attributes['received_at'] === null
                ? null
                : Carbon::parse($attributes['received_at'], $timezone)->utc();

            $existing = Lead::query()
                ->where('organization_id', $connection->organization_id)
                ->where('source', Lead::SOURCE_WORKIZ)
                ->where('remote_id', $attributes['remote_id'])
                ->first();

            /*
             * AND THE WATERMARK IS APPLIED HERE TOO, not only in the request -
             * but only to rows we do not already hold.
             *
             * `start_date` is DATE-granular, so asking Workiz for rows since
             * 2026-09-23 returns everything from midnight that day, including
             * rows from earlier the same morning. On a first run that is the
             * difference between importing nothing and importing today's book.
             *
             * It does NOT gate an UPDATE. The date is `CreatedDate`, when the
             * lead was created, and it never moves (`LeadDateTime` is the
             * appointment - measured by the Workiz session, 2026-09-28), so a
             * row whose status changed today still arrives with its original
             * date; gating on that would freeze `source_status` at whatever it
             * was the night we first saw it.
             *
             * A row with no date at all is kept: it cannot be placed against
             * the watermark, and missing a real enquiry is the worse error.
             */
            if ($existing === null && $receivedAt !== null && $receivedAt->lessThanOrEqualTo($since)) {
                $skipped++;

                continue;
            }

            /*
             * `status` is set ONCE, on creation, and never on update.
             *
             * It is EzBizi's own decision about the lead — new, booked,
             * archived — and Workiz has no say in it. An update that carried
             * status would walk back a dispatcher's decision every time the
             * import ran. Workiz's own state lives in `source_status`, which is
             * refreshed freely because it is theirs.
             */
            $row = array_merge($attributes, [
                'organization_id' => $connection->organization_id,
                // Read off the connection's scope the way LocalServicesLeads
                // does, rather than through a helper that does not exist.
                'company_id' => $connection->scope_type === SocialConnection::SCOPE_COMPANY
                    ? $connection->scope_id
                    : null,
                'franchise_id' => $connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                    ? $connection->scope_id
                    : null,
                'connection_id' => $connection->getKey(),
                'source' => Lead::SOURCE_WORKIZ,
                'received_at' => $receivedAt,
                'synced_at' => now(),
            ]);

            if ($existing === null) {
                $row['status'] = Lead::STATUS_NEW;
                Lead::create($row);
                $imported++;

                continue;
            }

            unset($row['status']);
            $existing->fill($row)->save();
        }

        return [$imported, $skipped];
    }

    /**
     * Does this row describe a channel EzBizi already ingests for itself?
     *
     * @param  array<string, mixed>  $payload
     */
    private function alreadyOurs(array $payload): bool
    {
        $source = strtolower((string) preg_replace(
            '/[^a-z0-9]/i',
            '',
            (string) ($payload['JobSource'] ?? ''),
        ));

        // An empty JobSource is 541 rows in A-1's own data and means "nobody
        // recorded where this came from". That is not evidence of a duplicate,
        // so it is imported — the exclusion has to be positive about what it
        // excludes.
        if ($source === '') {
            return false;
        }

        foreach ((array) SettingsResolver::forOrganization('leads.workiz_skip_sources') as $ours) {
            $ours = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $ours));
            if ($ours !== '' && str_contains($source, $ours)) {
                return true;
            }
        }

        return false;
    }
}
