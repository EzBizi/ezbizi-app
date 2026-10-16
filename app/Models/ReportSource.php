<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One Airtable table mapped into the reports (ReportSources). */
class ReportSource extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'base_id', 'table_id', 'role', 'company', 'filter_field', 'filter_value', 'fields'];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('A setting: replaced when it changes, holding no person.');
    }
}
