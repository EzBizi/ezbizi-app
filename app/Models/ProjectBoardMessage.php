<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One Project Management client text a job has had (ClientMessages); no personal data. */
class ProjectBoardMessage extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'job_id', 'kind'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('That a job\'s client was sent a given text. Deleted with the job.');
    }
}
