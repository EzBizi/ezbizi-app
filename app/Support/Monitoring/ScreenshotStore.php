<?php

namespace App\Support\Monitoring;

use Illuminate\Support\Facades\Storage;

/**
 * Where a device's periodic screen captures live on disk.
 *
 * Laravel's local disk, hardcoded, rather than through FileStore or a
 * caller-supplied disk: a screenshot is a short-lived JPEG the retention
 * sweep removes after a month, not a customer upload that may move to object
 * storage behind FILESYSTEM_DISK, so there is nothing here for that
 * abstraction to buy. This class is nonetheless the one place the disk is
 * named — every call site asks it for a path rather than reaching for
 * `Storage::disk('local')` itself — so if a later slice does need to move
 * these off the instance, this is the one file that changes.
 */
class ScreenshotStore
{
    private const DISK = 'local';

    /**
     * Write one screenshot's bytes and answer the relative path it landed at.
     *
     * `monitoring/screenshots/<org>/<device>/<captured_at>.jpg`. The
     * timestamp is sanitized for a filename — ISO 8601 is full of colons,
     * which are not valid in a Windows path.
     */
    public function put(string $organizationId, string $deviceId, string $capturedAt, string $jpegBytes): string
    {
        $path = sprintf(
            'monitoring/screenshots/%s/%s/%s.jpg',
            $organizationId,
            $deviceId,
            $this->sanitizeForFilename($capturedAt),
        );

        Storage::disk(self::DISK)->put($path, $jpegBytes);

        return $path;
    }

    /** Missing is not an error here: a caller checking a stale path gets ''. */
    public function get(string $path): string
    {
        return (string) Storage::disk(self::DISK)->get($path);
    }

    /** Missing is not an error either: deleting a file twice must not be one. */
    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    public function absolute(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    private function sanitizeForFilename(string $capturedAt): string
    {
        return preg_replace('/[^A-Za-z0-9]+/', '-', $capturedAt) ?? $capturedAt;
    }
}
