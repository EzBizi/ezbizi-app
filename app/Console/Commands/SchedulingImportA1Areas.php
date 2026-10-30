<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Bring EzBizi's service areas back in line with the ones Workiz is actually
 * booking into.
 *
 * ── Why this is a file and not a live connection ────────────────
 *
 * A1 Tools is read-only to us and
 * the read stays separable from the write, so the export can be inspected,
 * re-run and tested without a second live database handle. The query that
 * produces the file is in the spec beside this command's entry.
 *
 * ── Why a snapshot needs a date on it ───────────────────────────
 *
 * Jose, 2026-09-23: *"The Service Areas that we have on EzBizi are perfect as
 * they are… They already mirror workiz correctly."* That was true when the 26
 * were created and had quietly stopped being true: Workiz has CONSOLIDATED
 * since, and measured on jobs BOOKED in the last thirty days, plain "Atlanta"
 * took 80 while "Atlanta North" took none, and "Connecticut" took 209 against
 * 32 and 24 for its two halves.
 *
 * So the list this command reads is a measurement with an expiry, not a
 * configuration. It re-measures every run, and `--min-booked` is what keeps a
 * label that has stopped being used from being revived: an area is created
 * only where somebody is still booking into the name.
 *
 * ── What it will NOT do ─────────────────────────────────────────
 *
 * It never deletes or renames. An area of ours that the file does not mention
 * is REPORTED as a retire candidate and left alone — "Atlanta North" holds
 * capacity somebody configured, and a command that quietly dropped it would
 * take that with it. Retiring is Jose's call, one area at a time, in the
 * screen that shows what is attached.
 */
class SchedulingImportA1Areas extends Command
{
    protected $signature = 'scheduling:import-a1-areas
        {file : JSON array of {franchise, name, booked_30d} measured from Workiz history}
        {--organization= : Organization name, e.g. A1-Group}
        {--min-booked=1 : Skip names with fewer bookings than this in the measured window}
        {--dry-run : Report what would be written and write nothing}';

    protected $description = 'Create service areas for the Workiz names that have none';

    public function handle(TenantContext $tenant): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->error('That file is not a JSON array of {franchise, name, booked_30d}.');

            return self::FAILURE;
        }

        $organization = Organization::query()
            ->where('name', $this->option('organization'))
            ->first();
        if ($organization === null) {
            $this->error('Organization not found: '.$this->option('organization'));

            return self::FAILURE;
        }

        $tenant->set($organization);

        $minBooked = max(0, (int) $this->option('min-booked'));
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Keyed by NAME ACROSS THE ORGANIZATION, deliberately not per
         * franchise.
         *
         * The file says which franchise a name belongs to by looking at which
         * Workiz account books into it most, and that can disagree with where
         * the area already sits: "NYC" is under New Jersey here, "Pittsburgh,
         * PA" under Ohio. Keyed per franchise, a disagreement creates a SECOND
         * area with the same name under the other franchise, and nothing
         * errors — two capacity buckets for one territory, which is the exact
         * split this module exists to prevent.
         *
         * Safe because no A1-Group area name appears under two franchises
         * (checked 2026-09-23). If that ever stops being true this skips a
         * legitimate second area, which is the harmless direction: it declines
         * to create rather than quietly duplicating.
         */
        $existing = ServiceArea::query()->get()
            ->keyBy(fn (ServiceArea $a) => mb_strtolower(trim($a->name)));

        $created = 0;
        $skipped = 0;
        $seen = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $franchiseName = trim((string) ($row['franchise'] ?? ''));
            $booked = (int) ($row['booked_30d'] ?? 0);

            if ($name === '' || $franchiseName === '') {
                continue;
            }

            if ($booked < $minBooked) {
                $this->line("  skip  {$franchiseName} / {$name} — {$booked} booked in the window");
                $skipped++;

                continue;
            }

            $franchise = $this->franchise($organization, $franchiseName);
            if ($franchise === null) {
                $this->warn("  MISS  no franchise named {$franchiseName} — {$name} not created");
                $skipped++;

                continue;
            }

            /*
             * By NAME ONLY, matching the existence check above.
             *
             * Keyed by franchise this was WRONG IN THE REPORT and the dry-run
             * against production is what showed it: "Upstate New York" and
             * "New York" came back as retire candidates having taken 125 and 62
             * bookings in the window. Their busiest account is Nationwide while
             * the area sits under the New York franchise, so the pair never
             * matched and two live areas were named as dead. A retire list that
             * names a live area is worse than no retire list.
             */
            $seen[mb_strtolower($name)] = true;

            if (isset($existing[mb_strtolower($name)])) {
                continue;
            }

            $this->line(($dryRun ? '  would  ' : '  create ')."{$franchiseName} / {$name} ({$booked} booked)");

            if (! $dryRun) {
                ServiceArea::create([
                    'organization_id' => $organization->getKey(),
                    'franchise_id' => $franchise->getKey(),
                    'name' => $name,
                ]);
            }

            $created++;
        }

        // Ours that the measurement did not mention. Reported, never touched —
        // see the class docblock.
        $unmentioned = ServiceArea::query()->get()
            ->reject(fn (ServiceArea $a) => isset($seen[mb_strtolower(trim($a->name))]));

        if ($unmentioned->isNotEmpty()) {
            $this->newLine();
            $this->line('Ours with no booking in the measured window — RETIRE CANDIDATES, not touched:');
            foreach ($unmentioned as $area) {
                $this->line('  '.$area->name);
            }
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would create ' : 'Created ')."{$created}, skipped {$skipped}, "
            .$unmentioned->count().' unmentioned.');

        return self::SUCCESS;
    }

    /**
     * The franchise a name means, when the name is not unique.
     *
     * A1-Group's franchise list holds duplicates — Connecticut ×8, Texas ×3,
     * Nationwide ×2, DMV ×2, measured 2026-09-23 — from rows created on
     * 2026-09-10 and 2026-09-14 that carry no areas and no jobs. Resolving by
     * name alone would pick one at random and attach every new area to a row
     * nobody uses.
     *
     * So: the one that already has service areas, and failing that the OLDEST,
     * which is the 2026-08-06 batch every real franchise was created in. The
     * second rule is the one that matters, because Nationwide — the franchise
     * this command exists to fill — has zero areas on BOTH of its rows, so the
     * first rule cannot separate them.
     *
     * It says which it picked rather than choosing silently. Cleaning the
     * duplicates is not Scheduling's to do.
     */
    private function franchise(Organization $organization, string $name): ?Franchise
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $candidates = Franchise::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', $name)
            ->orderBy('created_at')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $this->resolved[$name] = $candidates->first();
        }

        $withAreas = $candidates->first(
            fn (Franchise $f) => ServiceArea::query()->where('franchise_id', $f->getKey())->exists()
        );

        $picked = $withAreas ?? $candidates->first();

        $this->warn("  {$candidates->count()} franchises named {$name}; using "
            .$picked->getKey().' ('.($withAreas !== null ? 'has areas' : 'oldest').')');

        return $this->resolved[$name] = $picked;
    }

    /** @var array<string, Franchise> Resolved once per name, so the duplicate warning prints once. */
    private array $resolved = [];
}
