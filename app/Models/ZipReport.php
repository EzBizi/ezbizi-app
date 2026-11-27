<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A ZIP somebody says resolved to the wrong area (Scheduling > Settings > Wrong ZIPs). */
#[Fillable(['organization_id', 'zip', 'reported_area_id', 'suggested_area_id', 'note', 'reported_by', 'closed_at', 'closed_by', 'moved_to_area_id'])]
class ZipReport extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'created_at', 'A staff report that a ZIP sits in the wrong service area; kept a year after for the history of area fixes.');
    }
}
