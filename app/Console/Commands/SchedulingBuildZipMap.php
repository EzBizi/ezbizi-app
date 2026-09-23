<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
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
 * ── The tie-break is the whole design ───────────────────────────
 *
 * `service_area_zips` is UNIQUE on (franchise_id, zip): one area per zip per
 * franchise, which is Jose's rule — "we should not share zip codes". Measured
 * against A-1's history, 420 zips are claimed by two or more areas and 339 of
 * those sit inside ONE Workiz account, so knowing the franchise settles
 * nothing. What settles it is which area has actually done the work there.
 *
 * Most jobs wins, and the runner-up is REPORTED rather than dropped in
 * silence: a zip that two areas both work is a real thing about the territory,
 * and the day somebody disputes the map that list is the answer.
 *
 * A zip CAN belong to two areas in two different franchises, and that is not a
 * tie — the unique index permits it and A-1 genuinely has zips either branch
 * may serve. One winner per franchise, not one winner overall.
 *
 * ── What it will not do ─────────────────────────────────────────
 *
 * It never invents an area. A job whose `service_area` matches nothing here
 * contributes no evidence and is counted into `--report-unmatched` instead:
 * under-constraining is safe, mis-attributing is not, which is the same rule
 * A1 applies when it refuses to guess an area for an ambiguous zip.
 *
 * It never overwrites a row a person typed. Derived rows carry
 * `source = 'workiz_jobs'` and only those are rewritten; a NULL source means
 * somebody entered it by hand and it wins over the evidence.
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
 * same as wrong in an obvious way: a contested zip decided on two days of
 * evidence can hand the zip to the area that happened to work there this week.
 * The next rebuild corrects it — the rows are derived and rewritten — but
 * between the two, availability answers for that zip name the wrong area, and
 * nothing about the output says the evidence was thin.
 *
 * Ask the Workiz session whether the A1 Tools history has been imported before
 * comparing what this produces against 2,717 — not whether "the backfill has
 * run", which is the question this file used to ask and which has no answer.
 */
class SchedulingBuildZipMap extends Command
{
    /** Derived rows carry one of these; a hand-typed row carries NULL and is never touched. */
    private const SOURCE = 'workiz_jobs';

    /**
     * The one-off seed from A1 Tools' own stored history.
     *
     * A separate word from SOURCE so the two are distinguishable in the table
     * afterwards — one is "our sync saw this", the other is "A-1's old system
     * saw this over eighteen months". Both are derived and both are
     * replaceable; only NULL is a person.
     */
    private const SOURCE_A1 = 'a1tools_jobs';

    protected $signature = 'scheduling:build-zip-map
        {--organization= : Organization name, e.g. A1-Group}
        {--file= : JSON array of {service_area, zip, city, jobs} exported from A1 Tools, instead of the live table}
        {--dry-run : Report what would be written and write nothing}
        {--report-unmatched : List the service-area names no area here matches}';

    protected $description = 'Derive the zip to service-area map from synced Workiz jobs';

    public function handle(TenantContext $tenant): int
    {
        $organization = Organization::query()
            ->where('name', $this->option('organization'))
            ->first();

        if ($organization === null) {
            $this->error('Organization not found: '.$this->option('organization'));

            return self::FAILURE;
        }

        $tenant->set($organization);
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Areas by lowercased name. The name is what Workiz reports and what
         * the areas were matched on when 19 of them were created on
         * 2026-09-23 — 26 of 26 already matched exactly, so nothing cleverer
         * than a trim and a case fold has ever been needed.
         */
        $areas = ServiceArea::query()->get();
        $byName = $areas->keyBy(fn (ServiceArea $a) => mb_strtolower(trim($a->name)));

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
                ->selectRaw('service_area, postal_code, COUNT(*) as jobs, MAX(city) as city')
                ->groupBy('service_area', 'postal_code')
                ->get();
        }

        if ($rows->isEmpty()) {
            $this->warn('Nothing carries both a service area and a postal code. '
                .'Nothing to derive — has the sync run?');

            return self::SUCCESS;
        }

        // (franchise, zip) => the best-evidenced claim, plus everyone who lost.
        $best = [];
        $unmatched = [];

        foreach ($rows as $row) {
            $zip = $this->zip((string) $row->postal_code);
            if ($zip === null) {
                continue;
            }

            $area = $byName->get(mb_strtolower(trim((string) $row->service_area)));

            if ($area === null) {
                $name = trim((string) $row->service_area);
                $unmatched[$name] = ($unmatched[$name] ?? 0) + (int) $row->jobs;

                continue;
            }

            $key = $area->franchise_id.'|'.$zip;
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
                $best[$key]['lost'][] = $best[$key]['area']->name.' ('.$best[$key]['jobs'].')';
                $lost = $best[$key]['lost'];
                $best[$key] = $claim + ['lost' => $lost];

                continue;
            }

            $best[$key]['lost'][] = $area->name.' ('.$claim['jobs'].')';
        }

        $written = 0;
        $kept = 0;
        $contested = 0;

        foreach ($best as $claim) {
            if ($claim['lost'] !== []) {
                $contested++;
            }

            $existing = ServiceAreaZip::query()
                ->where('franchise_id', $claim['area']->franchise_id)
                ->where('zip', $claim['zip'])
                ->first();

            // A person typed this one. Evidence does not outrank a decision.
            // Both derived sources replace each other; only NULL is protected.
            if ($existing !== null && $existing->source === null) {
                $kept++;

                continue;
            }

            if ($dryRun) {
                $written++;

                continue;
            }

            $attributes = [
                'service_area_id' => $claim['area']->getKey(),
                'city' => $claim['city'],
                'source' => $source,
            ];

            if ($existing !== null) {
                $existing->update($attributes);
            } else {
                ServiceAreaZip::create($attributes + [
                    'organization_id' => $organization->getKey(),
                    'franchise_id' => $claim['area']->franchise_id,
                    'zip' => $claim['zip'],
                ]);
            }

            $written++;
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
            '%s %d zip mappings. %d contested and decided on job count, %d left alone as hand-entered.',
            $dryRun ? 'Would write' : 'Wrote',
            $written,
            $contested,
            $kept,
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
