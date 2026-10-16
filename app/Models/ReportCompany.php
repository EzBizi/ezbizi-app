<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** The company code a branch reports as (ReportSources::companyOf). */
class ReportCompany extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'franchise_id', 'code'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('A setting: replaced when it changes, holding no person.');
    }
}
