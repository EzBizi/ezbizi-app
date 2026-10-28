<?php

namespace App\Support\Scheduling;

use App\Http\Controllers\Api\SchedulingAreaMapController;
use Illuminate\Support\Facades\DB;

/**
 * A whole state's ZIPs in one pick (Jose, 2026-10-28: "CT, MA, NH, RI").
 *
 * ponytail: by USPS 3-digit prefix, which is exact for nearly every ZIP; the
 * few that cross a border (Fishers Island NY on 063, DC's 569) follow their
 * prefix. Swap in a state column on zip_centroids if that ever matters.
 */
final class ZipStates
{
    /** @var array<string, list<array{0: int, 1: int}>> */
    public const PREFIXES = [
        'AL' => [[350, 369]], 'AK' => [[995, 999]], 'AZ' => [[850, 865]], 'AR' => [[716, 729]],
        'CA' => [[900, 961]], 'CO' => [[800, 816]], 'CT' => [[60, 69]], 'DE' => [[197, 199]],
        'DC' => [[200, 200], [202, 205], [569, 569]], 'FL' => [[320, 339], [341, 349]],
        'GA' => [[300, 319], [398, 399]], 'HI' => [[967, 968]], 'ID' => [[832, 838]], 'IL' => [[600, 629]],
        'IN' => [[460, 479]], 'IA' => [[500, 528]], 'KS' => [[660, 679]], 'KY' => [[400, 427]],
        'LA' => [[700, 714]], 'ME' => [[39, 49]], 'MD' => [[206, 219]], 'MA' => [[10, 27], [55, 55]],
        'MI' => [[480, 499]], 'MN' => [[550, 567]], 'MS' => [[386, 397]], 'MO' => [[630, 658]],
        'MT' => [[590, 599]], 'NE' => [[680, 693]], 'NV' => [[889, 898]], 'NH' => [[30, 38]],
        'NJ' => [[70, 89]], 'NM' => [[870, 884]], 'NY' => [[5, 5], [100, 149]], 'NC' => [[270, 289]],
        'ND' => [[580, 588]], 'OH' => [[430, 459]], 'OK' => [[730, 749]], 'OR' => [[970, 979]],
        'PA' => [[150, 196]], 'PR' => [[6, 9]], 'RI' => [[28, 29]], 'SC' => [[290, 299]],
        'SD' => [[570, 577]], 'TN' => [[370, 385]], 'TX' => [[750, 799], [885, 885]], 'UT' => [[840, 847]],
        'VT' => [[50, 54], [56, 59]], 'VA' => [[201, 201], [220, 246]], 'WA' => [[980, 994]],
        'WV' => [[247, 268]], 'WI' => [[530, 549]], 'WY' => [[820, 831]],
    ];

    /** @return list<string> the 3-digit prefixes of $state */
    private static function prefixes(string $state): array
    {
        $out = [];
        foreach (self::PREFIXES[$state] ?? [] as [$lo, $hi]) {
            foreach (range($lo, $hi) as $p) {
                $out[] = str_pad((string) $p, 3, '0', STR_PAD_LEFT);
            }
        }

        return $out;
    }

    /** @param list<string> $states @return list<string> */
    public static function zips(array $states): array
    {
        $prefixes = array_merge(...array_map(self::prefixes(...), $states ?: ['--']));

        return $prefixes === [] ? [] : DB::table('zip_centroids')
            ->whereIn(DB::raw('LEFT(zip, 3)'), $prefixes)->orderBy('zip')->pluck('zip')->map(fn ($z) => (string) $z)->all();
    }

    /** The state drawn on the map: its ZIP centres' outline, for looking at only. */
    public static function outline(string $state): array
    {
        $points = DB::table('zip_centroids')->whereIn(DB::raw('LEFT(zip, 3)'), self::prefixes($state) ?: ['--'])
            ->get(['latitude', 'longitude'])->map(fn ($c) => [(float) $c->latitude, (float) $c->longitude])->all();

        return count($points) < 3 ? [] : SchedulingAreaMapController::buffered(SchedulingAreaMapController::hull($points), 1.0);
    }
}
