<?php

namespace App\Support\Monitoring;

use Illuminate\Support\Facades\Storage;

/**
 * Where a device's LIVE (ephemeral) frame lives on disk — one JPEG per
 * device, overwritten every capture, never accumulated.
 *
 * ── Why this is not ScreenshotStore ───────────────────────────────
 *
 * ScreenshotStore writes one file per CAPTURE, timestamped, feeding the
 * 30-day-retained `monitoring_screenshots` table. Live View is a different
 * thing entirely: a manager watching a screen update every ~1-2s while they
 * are watching, and nothing kept once they look away. There is no row to
 * attach a path to and no history to browse — see LiveViewController's
 * class docblock for the full reasoning — so the path this class hands back
 * names the DEVICE, not a moment in time, and every `put()` for that device
 * replaces whatever was there.
 *
 * // ponytail: no stale-file sweep. One file per device means the disk
 * // footprint is bounded by the device count regardless of how long ago
 * // anyone last watched — there is nothing here that grows, so there is
 * // nothing here to prune. A sweep would be a cron job maintaining an
 * // invariant the file layout already guarantees for free.
 */
class LiveFrameStore
{
    private const DISK = 'local';

    /**
     * Write this device's latest frame and answer the path it landed at.
     *
     * `monitoring/live/<org>/<device>.jpg` — no timestamp component, unlike
     * ScreenshotStore::put: that is the whole mechanism. The same call next
     * second overwrites the same file rather than writing a new one.
     */
    public function put(string $organizationId, string $deviceId, string $jpegBytes): string
    {
        $path = sprintf('monitoring/live/%s/%s.jpg', $organizationId, $deviceId);

        Storage::disk(self::DISK)->put($path, $jpegBytes);

        return $path;
    }

    /** Missing is not an error here: a caller checking a stale/absent frame gets ''. */
    public function get(string $organizationId, string $deviceId): string
    {
        return (string) Storage::disk(self::DISK)->get($this->path($organizationId, $deviceId));
    }

    /**
     * Remove a device's live frame — for DevicePruner, when the device itself is
     * being swept. Missing is not an error either: a device that was never
     * watched has no frame, and deleting one twice must not fail (same contract
     * as ScreenshotStore::delete).
     */
    public function delete(string $organizationId, string $deviceId): void
    {
        Storage::disk(self::DISK)->delete($this->path($organizationId, $deviceId));
    }

    public function absolute(string $organizationId, string $deviceId): string
    {
        return Storage::disk(self::DISK)->path($this->path($organizationId, $deviceId));
    }

    private function path(string $organizationId, string $deviceId): string
    {
        return sprintf('monitoring/live/%s/%s.jpg', $organizationId, $deviceId);
    }
}
