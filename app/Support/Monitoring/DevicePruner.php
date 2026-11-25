<?php

namespace App\Support\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;

/**
 * The scheduled sweep behind `ezbizi:prune-devices`.
 *
 * `monitored_devices` is one-current-row-per-(tenant, person, device), never a
 * log — so nothing trims it on its own. Every browser a person ever signed in
 * from and every machine that was retired leaves a row behind, and the roster
 * grows forever. This is the sweep that bounds it: a device whose last beat is
 * past the retention window is gone, and it takes ITS screenshots, ITS live
 * frame and ITS activity with it.
 *
 * Mirrors {@see ScreenshotPruner} deliberately — plucked organization ids, one
 * `TenantContext::run()` per organization because `MonitoredDevice`'s own tenant
 * scope requires one, and the same file-before-row discipline for every JPEG.
 *
 * ── Why the delete order is files → rows → frame → device ─────────
 *
 * A stale device is not a plain row: `monitoring_screenshots` rows are the index
 * to JPEGs on disk. Both `monitoring_screenshots.device_id` and
 * `monitoring_activity.device_id` are `cascadeOnDelete` (see their migrations),
 * so deleting the device row ALONE would cascade both child tables' ROWS at the
 * database — but a DB cascade cannot reach a file, so every one of that device's
 * screenshot JPEGs would orphan on disk, exactly the failure ScreenshotPruner
 * exists to close. So this deletes, for each stale device:
 *
 *   1. each screenshot's FILE, then its ROW — same file-then-row path as
 *      ScreenshotPruner (via ScreenshotStore), so a crash between the two leaves
 *      a JPEG-less row the next run re-selects and finishes, never an orphaned
 *      JPEG. This is load-bearing, not cosmetic: a leftover file is a person's
 *      screen image still on disk after their device row is gone.
 *   2. the live frame file (via LiveFrameStore) — one overwriting JPEG per
 *      device, no row to index it, so nothing else would ever remove it.
 *   3. the device ROW — LAST, precisely because its `cascadeOnDelete` would
 *      otherwise take the screenshot rows (and their paths) with it BEFORE the
 *      files were deleted. Deleting it last means the cascade fires only after
 *      every file is already gone. Its cascade is also what removes the
 *      `monitoring_activity` rows: they carry no file, so the DB cascade is the
 *      whole job and an explicit delete would be redundant.
 *
 * Self-healing throughout: a missing file (ScreenshotStore/LiveFrameStore treat
 * a second delete as a no-op) and an already-gone child are not errors, so a
 * half-finished run is simply completed by the next one.
 *
 * // ponytail: this is retention HYGIENE, not a §9 erasure trigger — it removes
 * // whole abandoned devices on a clock, not a named person's data on request.
 * // But it MUST NOT orphan a screenshot JPEG (that would strand a picture of a
 * // person's screen with no row naming it), so files-before-rows above is the
 * // one part that cannot be simplified away.
 */
class DevicePruner
{
    /**
     * A device silent this many days is stale and swept.
     *
     * // ponytail: the one knob. Flat 30 days for every tenant on purpose — a
     * // per-tenant override is a column and a UI nobody has asked for yet;
     * // dial it here, or make it per-tenant later if a customer ever needs it.
     */
    public const RETENTION_DAYS = 30;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ScreenshotStore $screenshots,
        private readonly LiveFrameStore $liveFrames,
    ) {}

    public function prune(): int
    {
        $count = 0;

        foreach (Organization::query()->pluck('id') as $organizationId) {
            $organization = Organization::query()->find($organizationId);

            if ($organization === null) {
                continue;
            }

            $count += $this->tenant->run($organization, function (): int {
                $cutoff = now()->subDays(self::RETENTION_DAYS);

                // Stale = last beat older than the window. A device that never
                // beat (null last_seen_at) is judged by when it was created, so
                // a row that was inserted and never checked in again still ages
                // out instead of living forever.
                $stale = MonitoredDevice::query()
                    ->where(function ($query) use ($cutoff) {
                        $query
                            ->where('last_seen_at', '<', $cutoff)
                            ->orWhere(fn ($q) => $q
                                ->whereNull('last_seen_at')
                                ->where('created_at', '<', $cutoff));
                    })
                    ->get();

                // Deleted in platform scope, the way expire-data runs: retention
                // is owed even by an organization whose subscription has ended,
                // and its read-only guard refused the delete and aborted the
                // whole run (2026-11-25 04:31 UTC).
                foreach ($stale as $device) {
                    $this->tenant->runWithoutTenant(fn () => $this->pruneDevice($device));
                }

                return $stale->count();
            });
        }

        return $count;
    }

    private function pruneDevice(MonitoredDevice $device): void
    {
        // 1. Screenshots: FILE then ROW, so no JPEG is left behind when the
        //    device row's cascade later clears any remaining screenshot rows.
        $shots = MonitoringScreenshot::query()
            ->where('device_id', $device->id)
            ->get();

        foreach ($shots as $shot) {
            $this->screenshots->delete($shot->path);
            $shot->delete();
        }

        // 2. The device's single live-view frame (missing is a no-op).
        $this->liveFrames->delete($device->organization_id, $device->id);

        // 3. The device row, LAST. Its cascadeOnDelete FK removes the
        //    monitoring_activity rows (no file, so the DB cascade is enough) and
        //    any screenshot rows — which by here have all been deleted above.
        $device->delete();
    }
}
