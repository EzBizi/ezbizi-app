<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A saved question about the calls. */
#[Fillable([
    'name', 'period', 'from_date', 'to_date', 'group_by', 'filters', 'created_by',
])]
class PhoneReport extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /**
     * Relative windows, because a question worth saving is asked repeatedly.
     * `custom` is the one-off, and stores its dates alongside.
     */
    public const PERIODS = [
        'last_7', 'last_30', 'last_90', 'this_month', 'last_month', 'custom',
    ];

    // `key` and `caller`: Jose, 2026-10-05, "what client pressed on the ivr
    // menu" and "where did the calls come from".
    public const GROUPINGS = ['day', 'hour', 'tag', 'user', 'line', 'direction', 'key', 'caller'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A saved report describes a question, not a person. Removing the '
            .'organization takes the row.'
        );
    }

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'from_date' => 'date',
            'to_date' => 'date',
        ];
    }
}
