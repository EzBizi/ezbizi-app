<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One scan of a printed QR code. Written by the public redirect through the
 * query builder (it has no tenant); read here for the stats.
 */
class QrCodeScan extends Model implements StatesRetention
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(13, 'scanned_at',
            'When a printed QR code was scanned, for the chart on its card. No IP address, '
            .'device or location is kept: only a fingerprint salted with a value that is '
            .'replaced every day and discarded, so it can tell two phones apart on one day '
            .'and identifies nobody afterwards. Thirteen months covers a year against the '
            .'same month the year before; the code\'s lifetime total is kept on the code.'
        );
    }

    /**
     * Same phone, same day → same value; anything else → unrelated values.
     *
     * The salt lives only in the cache, for two days, and is random: unlike
     * one derived from APP_KEY it cannot be recomputed later to test whether a
     * given IP scanned on a given date.
     *
     * /privacy/ promises exactly this (67b7bbc3): never persist the salt, fix
     * it, or key it longer than a UTC day, or that page becomes false.
     *
     * ponytail: UTC days, so a phone scanning either side of UTC midnight
     * counts twice in a Miami evening. Key the salt by the org's timezone if
     * that ever matters.
     */
    public static function visitorFor(?string $ip, ?string $userAgent): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $day = now()->utc()->toDateString();
        $salt = Cache::remember('qr-scan-salt:'.$day, now()->addDays(2), fn () => bin2hex(random_bytes(32)));

        return hash('sha256', $salt.'|'.$ip.'|'.(string) $userAgent);
    }
}
