<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

/** A US ZIP's centre (Census ZCTA Gazetteer). Public reference data, no tenant. */
class ZipCentroid extends Model implements StatesRetention
{
    protected $primaryKey = 'zip';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * A ZIP's centre: the Census table, else Zippopotam (PO-box and unique
     * ZIPs have no ZCTA), kept here once found. Null only for a ZIP that
     * does not exist (Jose, 2026-10-28: any valid ZIP can be a centre).
     */
    public static function locate(string $zip): ?self
    {
        if ($known = self::query()->find($zip)) {
            return $known;
        }
        try {
            $place = Http::timeout(5)->get("https://api.zippopotam.us/us/{$zip}")->json('places.0');
        } catch (\Throwable) {
            return null;
        }

        return is_array($place) && isset($place['latitude'], $place['longitude'])
            ? self::query()->create(['zip' => $zip, 'latitude' => (float) $place['latitude'], 'longitude' => (float) $place['longitude']])
            : null;
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Public Census reference data about places, not people; replaced whole by scheduling:load-zip-centroids.');
    }
}
