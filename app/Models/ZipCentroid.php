<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;

/** A US ZIP's centre (Census ZCTA Gazetteer). Public reference data, no tenant. */
class ZipCentroid extends Model implements StatesRetention
{
    protected $primaryKey = 'zip';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Public Census reference data about places, not people; replaced whole by scheduling:load-zip-centroids.');
    }
}
