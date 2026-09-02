<?php

namespace App\Support\Monitoring;

use App\Models\MonitoringScreenshot;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;

/**
 * The scheduled sweep behind `ezbizi:prune-screenshots`.
 *
 * `MonitoringScreenshot::retention()` states `elsewhere` rather than `window`
 * precisely because this exists: `ezbizi:expire-data` deletes a swept table's
 * expired rows with one raw `DB::table()->delete()`, which is fast and safe
 * for a plain row but never fires an Eloquent event — so it cannot be trusted
 * to take a JPEG on disk with it. This class is that "somewhere else",
 * deleting the FILE before the ROW for each expired screenshot.
 *
 * ── Why file-then-row, when Attachment does the opposite ──────────
 *
 * AttachmentsController::destroy deletes the row first and the file after,
 * deliberately, because a mid-crash there should leave bytes with no row
 * rather than a row pointing at nothing — and nothing revisits that row again,
 * so the row is the last chance to know the file needs deleting.
 *
 * A scheduled sweep is a different shape of caller: it runs again every night,
 * selecting by the same `captured_at < cutoff` condition. Deleting the row
 * first here would mean a mid-crash leaves an ORPHANED FILE — no row to name
 * it, so nothing left in the system ever asks for it again, which is exactly
 * the failure this class exists to close. Deleting the file first means a
 * mid-crash leaves a row with a missing file, and that row is still older
 * than the cutoff — so tomorrow's run selects it again, asks ScreenshotStore
 * to delete a path that is already gone (a no-op; see FileStore::delete's own
 * "deleting a file twice must not be an error"), and finishes the row. The
 * failure heals itself on the very next run instead of needing a second
 * mechanism nobody has built.
 *
 * Pattern: {@see \App\Support\Monitoring\DeviceOfflineWorker} — plucked
 * organization ids, one `TenantContext::run()` per organization, the same
 * shape `ezbizi:expire-data` uses per swept table except walked per tenant
 * because `MonitoringScreenshot`'s own tenant scope requires one, unlike
 * ExpireData's `withoutGlobalScopes()` cross-tenant sweep.
 */
class ScreenshotPruner
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ScreenshotStore $store,
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
                $cutoff = now()->subDays(MonitoringScreenshot::RETENTION_DAYS);

                $expired = MonitoringScreenshot::query()
                    ->where('captured_at', '<', $cutoff)
                    ->get();

                foreach ($expired as $screenshot) {
                    $this->store->delete($screenshot->path);
                    $screenshot->delete();
                }

                return $expired->count();
            });
        }

        return $count;
    }
}
