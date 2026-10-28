<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\ServiceAreaZipRelease;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The zip → service area map, derived from Workiz job history.
 *
 * Nobody types 2,700 zip codes. A1 Tools does not either: its map is built by
 * counting (PostalCode, ServiceArea) pairs across every synced job, and the
 * count is kept because it is what settles a zip that two areas both claim.
 * This is the same derivation over the Workiz session's `workiz_jobs` landing
 * table — `service_area` is trimmed on write there, so no string work here.
 *
 * ── It ADDS zips. It never moves one. ───────────────────────────
 *
 * `service_area_zips` is UNIQUE on (organization_id, zip) since 2026-09-25: one
 * area per zip in the whole organization, which is Jose's rule — "we should not
 * share zip codes". It was per franchise until A-1 turned out to have 66 zips
 * on two branches' areas at once, most of them New York copies of zips other
 * areas owned (the Nationwide call centre tags out-of-state jobs "New York").
 *
 * This command used to settle a contested zip by most jobs, re-decided on every
 * run. It no longer does, because that rule does not hold on A-1's data — A1
 * Tools measured it: 136 of 169 contested zips were exact ties, and of the 33 it
 * could decide it got 7 wrong, all on metro borders. So a zip with no area is
 * added to the best-evidenced one, and a zip that ALREADY has an area keeps it.
 * When the evidence disagrees with the map, the zip is listed for a person to
 * judge and left where it is.
 *
 * ── It runs by itself ────────────────────────────────────────────
 *
 * Every five minutes, after the Workiz projection, for every organization with
 * Workiz jobs (routes/console.php). Jose, 2026-09-28: a customer booked in
 * Workiz for Tampa at a zip Tampa has never had should put that zip on Tampa,
 * the way A1 Tools' sync does on every tick. A1 also CREATES an area for any
 * name it has not seen, which Jose called a flaw; this never does. And A1
 * matches the name across the whole company, which is the collision fixed
 * below; this matches it within the job's own branch.
 *
 * ── What it will not do ─────────────────────────────────────────
 *
 * It never invents an area. A job whose `service_area` matches nothing here
 * contributes no evidence and is counted into `--report-unmatched` instead:
 * under-constraining is safe, mis-attributing is not, which is the same rule
 * A1 applies when it refuses to guess an area for an ambiguous zip.
 *
 * It never moves or rewrites an existing row, so a mapping a person typed or
 * corrected is safe by construction. `source` still records where a row came
 * from — NULL a person, `workiz_jobs` or `a1tools_jobs` a derivation.
 *
 * ── WHAT THIS CAN SEE, AND WHAT IT CANNOT ──────────────────────
 *
 * THE HISTORY DOES NOT COME FROM WORKIZ AT ALL, and this paragraph said the
 * opposite for several hours. It said to ask for a backfill with `--since=` or
 * a wide window. There is no such run: Workiz measured their own API and
 * `/job/all/` IGNORES `start_date` and does not page — it returns roughly the
 * next hundred UPCOMING appointments and nothing else.
 *
 * Corroborated here rather than relayed. Production `workiz_jobs`, 2026-09-23:
 * 637 rows, 537 of them scheduled in the FUTURE, earliest 2026-09-21 and
 * latest 2027-04-01. A window parameter that worked would not produce that.
 *
 * A-1's 7,793 historical jobs exist in A1 Tools only because its own sync
 * caught each one while it was still upcoming, every two minutes, for months.
 * They are recoverable from `a1tools_workiz_jobs` on the same box and the
 * Workiz session is building that importer; until it runs, this command sees
 * only what is upcoming. Its derived map is 2,935 rows over 2,717 distinct
 * zips, which is what a complete one looks like.
 *
 * So a run against two days of jobs produces a THIN map, and thin is not the
 * same as wrong in an obvious way: a zip decided on two days of evidence can go
 * to the area that happened to work there this week. Since 2026-09-25 a later
 * run does NOT correct it — existing zips are never moved — so the list of
 * disputed zips it prints is the only place a thin first decision surfaces.
 *
 * Ask the Workiz session whether the A1 Tools history has been imported before
 * comparing what this produces against 2,717 — not whether "the backfill has
 * run", which is the question this file used to ask and which has no answer.
 */
class SchedulingBuildZipMap extends Command
{
    /** Rows this command adds carry one of these; a hand-typed row carries NULL. */
    private const SOURCE = 'workiz_jobs';

    /**
     * The one-off seed from A1 Tools' own stored history.
     *
     * A separate word from SOURCE so the two are distinguishable in the table
     * afterwards — one is "our sync saw this", the other is "A-1's old system
     * saw this over eighteen months". Neither is ever rewritten by a later
     * run; only NULL is a person.
     */
    private const SOURCE_A1 = 'a1tools_jobs';

    protected $signature = 'scheduling:build-zip-map
        {--organization= : Organization name, e.g. A1-Group; every organization with Workiz jobs when omitted}
        {--file= : JSON array of {service_area, zip, city, jobs} exported from A1 Tools, instead of the live table}
        {--dry-run : Report what would be written and write nothing}
        {--report-unmatched : List the service-area names no area here matches}';

    protected $description = 'Derive the zip to service-area map from synced Workiz jobs';

    public function handle(TenantContext $tenant): int
    {
        if ($this->option('organization') === null) {
            if ($this->option('file') !== null) {
                $this->error('An A1 Tools export belongs to one organization: pass --organization.');

                return self::FAILURE;
            }

            // The scheduled run: every organization that has Workiz jobs.
            $ids = DB::table('workiz_jobs')->distinct()->pluck('organization_id');

            foreach (Organization::query()->whereIn('id', $ids)->orderBy('name')->get() as $organization) {
                $this->line("— {$organization->name}");
                $this->build($organization, $tenant);
            }

            return self::SUCCESS;
        }

        $organization = Organization::query()
            ->where('name', $this->option('organization'))
            ->first();

        if ($organization === null) {
            $this->error('Organization not found: '.$this->option('organization'));

            return self::FAILURE;
        }

        return $this->build($organization, $tenant);
    }

    private function build(Organization $organization, TenantContext $tenant): int
    {
        $tenant->set($organization);
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Areas by FRANCHISE and lowercased name. The name is what Workiz
         * reports, trimmed and case-folded — but a name is only unique within
         * its branch's Workiz account. On 2026-09-28 Nationwide gained Austin,
         * Houston and San Antonio beside Texas's own, and a name-only lookup
         * (what this was) credited Texas's zips to Nationwide's Austin. A job
         * from the live table carries its franchise, so it matches only its
         * own branch's area.
         *
         * The A1 export (--file) carries no franchise: a name there matches
         * only when exactly one area has it, and is reported otherwise.
         */
        $areas = ServiceArea::query()->get();
        $fold = fn (?string $name) => mb_strtolower(trim((string) $name));
        $byBranch = $areas->keyBy(fn (ServiceArea $a) => $a->franchise_id.'|'.$fold($a->name));
        $byName = $areas->groupBy(fn (ServiceArea $a) => $fold($a->name));
        $branchNames = Franchise::query()->whereIn('id', $areas->pluck('franchise_id'))->pluck('name', 'id');
        $label = fn (ServiceArea $a) => ($branchNames[$a->franchise_id] ?? '?').' › '.$a->name;

        $file = $this->option('file');
        $source = $file === null ? self::SOURCE : self::SOURCE_A1;

        if ($file !== null) {
            if (! is_file($file)) {
                $this->error("No such file: {$file}");

                return self::FAILURE;
            }

            $decoded = json_decode((string) file_get_contents($file), true);

            if (! is_array($decoded)) {
                $this->error('That file is not a JSON array of {service_area, zip, city, jobs}.');

                return self::FAILURE;
            }

            $rows = collect($decoded)->map(fn (array $r) => (object) [
                'service_area' => $r['service_area'] ?? null,
                'postal_code' => $r['zip'] ?? null,
                'city' => $r['city'] ?? null,
                'jobs' => (int) ($r['jobs'] ?? 0),
            ])->filter(fn ($r) => $r->service_area !== null && $r->postal_code !== null);
        } else {
            $rows = DB::table('workiz_jobs')
                ->where('organization_id', $organization->getKey())
                ->whereNotNull('service_area')
                ->whereNotNull('postal_code')
                ->selectRaw('franchise_id, service_area, postal_code, COUNT(*) as jobs, MAX(city) as city')
                ->groupBy('franchise_id', 'service_area', 'postal_code')
                ->get();
        }

        if ($rows->isEmpty()) {
            $this->warn('Nothing carries both a service area and a postal code. '
                .'Nothing to derive — has the sync run?');

            return self::SUCCESS;
        }

        // zip => the best-evidenced claim, plus everyone who lost.
        $best = [];
        $unmatched = [];

        foreach ($rows as $row) {
            $zip = $this->zip((string) $row->postal_code);
            if ($zip === null) {
                continue;
            }

            $name = $fold($row->service_area);
            $area = isset($row->franchise_id)
                ? $byBranch->get($row->franchise_id.'|'.$name)
                : (($byName->get($name)?->count() ?? 0) === 1 ? $byName->get($name)->first() : null);

            if ($area === null) {
                $name = trim((string) $row->service_area);
                $unmatched[$name] = ($unmatched[$name] ?? 0) + (int) $row->jobs;

                continue;
            }

            // One area per zip in the ORGANIZATION (2026-09-25), so the
            // contest is between every area that names this zip, whichever
            // branch it belongs to.
            $key = $zip;
            $claim = [
                'area' => $area,
                'zip' => $zip,
                'city' => $row->city === null ? null : trim((string) $row->city),
                'jobs' => (int) $row->jobs,
            ];

            if (! isset($best[$key])) {
                $best[$key] = $claim + ['lost' => []];

                continue;
            }

            if ($claim['jobs'] > $best[$key]['jobs']) {
                $best[$key]['lost'][] = $label($best[$key]['area']).' ('.$best[$key]['jobs'].')';
                $lost = $best[$key]['lost'];
                $best[$key] = $claim + ['lost' => $lost];

                continue;
            }

            $best[$key]['lost'][] = $label($area).' ('.$claim['jobs'].')';
        }

        $written = 0;
        $unchanged = 0;
        $disputed = [];

        // A zip a person took out of its area stays out (2026-10-21).
        $released = ServiceAreaZipRelease::query()->get(['company_id', 'zip'])->mapWithKeys(fn ($r) => [$r->company_id.'|'.$r->zip => true]);
        $companyOf = Franchise::query()->pluck('company_id', 'id');

        foreach ($best as $claim) {
            $company = $companyOf[$claim['area']->franchise_id] ?? null;
            if (isset($released[$company.'|'.$claim['zip']])) {
                continue;
            }
            $existing = ServiceAreaZip::query()->where('company_id', $company)->where('zip', $claim['zip'])->first();

            /*
             * A ZIP THAT ALREADY HAS AN AREA KEEPS IT. New zips are added; an
             * existing one is never moved, whoever set it and whatever the
             * evidence now says.
             *
             * This used to follow the evidence — most jobs wins, re-decided on
             * every run. A1 Tools measured that rule on A-1's own history on
             * 2026-09-25 and it does not hold: of 169 zips two areas contested,
             * 136 were exact ties, and of the 33 it could decide it got 7
             * WRONG, every one a metro border that matters (46410/46323/46375
             * belong to Indiana and it picked Chicago; 20601 belongs to
             * Maryland and it picked Virginia). A count cannot tell a crew that
             * works a zip from a call centre that mis-tags it. The map was
             * cleaned by hand the same day; a rebuild re-deciding it would undo
             * that cleaning on the next run.
             *
             * So disagreements are REPORTED for a person to judge, never applied.
             */
            if ($existing !== null) {
                if ($existing->service_area_id === $claim['area']->getKey()) {
                    $unchanged++;
                } else {
                    $disputed[] = sprintf(
                        '%s  on %s; the evidence says %s (%d jobs)',
                        $claim['zip'],
                        $existing->serviceArea === null ? '?' : $label($existing->serviceArea),
                        $label($claim['area']),
                        $claim['jobs'],
                    );
                }

                continue;
            }

            if (! $dryRun) {
                ServiceAreaZip::create([
                    'organization_id' => $organization->getKey(),
                    'franchise_id' => $claim['area']->franchise_id,
                    'service_area_id' => $claim['area']->getKey(),
                    'zip' => $claim['zip'],
                    'city' => $claim['city'],
                    'source' => $source,
                ]);
            }

            $written++;
        }

        if ($disputed !== []) {
            $this->newLine();
            $this->line('Zips the evidence would put on a different area — LEFT AS THEY ARE, for a person to judge:');
            foreach ($disputed as $line) {
                $this->line('  '.$line);
            }
        }

        if ($this->option('report-unmatched') && $unmatched !== []) {
            arsort($unmatched);
            $this->newLine();
            $this->line('Workiz service-area names with no area here — their jobs are NOT mapped:');
            foreach ($unmatched as $name => $jobs) {
                $this->line(sprintf('  %-28s %d jobs', $name, $jobs));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d new zips. %d already on the area the evidence names; %d on another area and left there.',
            $dryRun ? 'Would add' : 'Added',
            $written,
            $unchanged,
            count($disputed),
        ));

        return self::SUCCESS;
    }

    /**
     * Five digits, or nothing.
     *
     * Workiz carries `06457-1234` and the occasional `6457`. A1 pads and
     * truncates the same way; anything shorter than four digits is not a zip
     * and is dropped rather than padded into a real one somewhere else.
     */
    private function zip(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '' || strlen($digits) < 4) {
            return null;
        }

        return substr(str_pad($digits, 5, '0', STR_PAD_LEFT), 0, 5);
    }
}
