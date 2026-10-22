<?php

namespace App\Support\Jobs;

use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;

/**
 * Which of Scheduling's service areas a job belongs to.
 *
 * A service area is a sub-division WITHIN a franchise (Jose, 2026-09-25). The
 * rule, agreed with the Scheduling session and resting on Jose's 2026-08-31
 * ruling that the area somebody CHOSE beats the one a zip SUGGESTS:
 *
 *   1. The assigned area -- Workiz's name -- if it is an area of this franchise.
 *   2. Else the area the job's zip falls in, if that area is in this franchise.
 *   3. Else none. Never another franchise's area. Empty is the safe direction:
 *      it says "look at this job" where a wrong area would be believed.
 *
 * ── What this deliberately does NOT do ───────────────────────────
 *
 * No alias table. Workiz sends only its current area names, and those are the
 * 33 Jose consolidated into EzBizi. Folding retired names (East Connecticut ->
 * Connecticut) belongs to the ONE-OFF historical backfill, never here: an alias
 * list in the live path would be a second copy of Jose's area list, and it
 * would drift from the first.
 *
 * No logging of disagreements. A pure read. A write path that also keeps a
 * diary is two jobs, and the diary is the one that rots (Scheduling's rule). A
 * live list of where the name and the zip disagree is a read-only query over
 * workiz_jobs, and Scheduling owns it.
 *
 * ── Tenant context is REQUIRED ───────────────────────────────────
 *
 * ServiceArea and ServiceAreaZip use BelongsToTenant, so this must run with an
 * organization in context. Without one, TenantScope REFUSES the read rather
 * than returning null. That is deliberate and not wrapped away here: a
 * resolver that silently set its own tenant would read another organization's
 * areas the day it was handed the wrong franchise.
 */
final class JobServiceArea
{
    public static function resolve(Franchise $franchise, ?string $name, ?string $zip): ?string
    {
        if (filled($name)) {
            $id = ServiceArea::query()
                ->where('franchise_id', $franchise->getKey())
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
                ->value('id');

            if ($id !== null) {
                return $id;
            }
        }

        $zip5 = self::zip5($zip);

        if ($zip5 === '') {
            return null;
        }

        // Through the AREA's franchise, not the zip row's denormalised copy of
        // it. Today every writer sets both together, so they agree -- but a
        // data fix that re-homes an area leaves its zips' copies behind (A1
        // Tools hit exactly that), and a job must not be placed by the stale
        // one. Scheduling's hardening, 2026-09-25.
        $own = ServiceAreaZip::query()
            ->where('zip', $zip5)
            ->whereHas('serviceArea', fn ($q) => $q->where('franchise_id', $franchise->getKey()))
            ->value('service_area_id');

        // A franchise's area that shares another's pool covers that pool's
        // zips. Boston North, 2026-10-05: MA's row holds the zips and
        // Nationwide's row shares MA's pool, so a Nationwide job in those zips
        // tagged anything else ("Boston South", nothing: about 7% of them) is
        // still Nationwide's Boston North rather than nowhere.
        return $own ?? ServiceArea::query()
            ->where('franchise_id', $franchise->getKey())
            ->whereIn('shares_with_id', ServiceAreaZip::query()->where('zip', $zip5)->select('service_area_id'))
            ->value('id')
            // A branch's catch-all area takes any ZIP none of its areas lists.
            ?? ServiceArea::query()->where('franchise_id', $franchise->getKey())
                ->where('covers_all_zips', true)->orderBy('created_at')->value('id');
    }

    /**
     * Five digits, the way Scheduling's importer and A1 Tools' both store zips.
     *
     * New England zips arrive without their leading zero -- "6457" is "06457" --
     * so a four-digit zip is left-padded. Taking the first five digits alone
     * leaves "6457", which matches no row and places the job nowhere, with no
     * error. Fewer than four digits is not a zip.
     */
    private static function zip5(?string $zip): string
    {
        $digits = preg_replace('/\D+/', '', (string) $zip);

        return strlen($digits) < 4
            ? ''
            : substr(str_pad($digits, 5, '0', STR_PAD_LEFT), 0, 5);
    }
}
