<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One building code an inspection can cite.
 *
 * Written once in the library, cited from as many fields as need it, corrected
 * in one place. A1's tool cites `R1003.11` from five different fields; free
 * text on each would be five copies that drift.
 *
 * ── The citation FREEZES into the version, not into this row ─────
 *
 * A field carries `code_id` while it is a draft. Publishing resolves it and
 * writes the code, title and body into the version's screens as text — and a
 * version is written once. So correcting a typo in this library tomorrow does
 * not rewrite what a report published today said the violation was, for exactly
 * the reason a bound customer name is frozen at submit.
 *
 * That means this row is CONFIGURATION and never evidence, which is what lets
 * it be edited and deleted freely.
 */
class InspectionCode extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A building code this organization cites on its inspections. It is a reference '
            .'to a published standard and holds no information about any person. Published '
            .'versions and submitted reports keep their own copy of the wording, so '
            .'correcting or removing an entry here does not change what any report already '
            .'said. Removing the organization takes it.'
        );
    }

    protected $fillable = [
        'organization_id', 'code', 'title', 'body',
    ];
}
