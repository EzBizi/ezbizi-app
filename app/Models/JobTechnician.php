<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Who is on a job. `source` workiz is imported and may be replaced by an import; ezbizi never is. */
#[Fillable(['job_id', 'technician_id', 'source'])]
class JobTechnician extends Model implements StatesRetention
{
    public const EZBIZI = 'ezbizi';

    public const WORKIZ = 'workiz';

    public $incrementing = false;

    protected $primaryKey = null;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('Who did a job is part of the job, and goes with it.');
    }
}
