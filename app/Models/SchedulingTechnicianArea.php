<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;

/** Which service areas a technician covers (the `scheduling_technician_areas` pivot). */
class SchedulingTechnicianArea extends Model implements StatesRetention
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('Part of the technician, and goes with them.');
    }
}
