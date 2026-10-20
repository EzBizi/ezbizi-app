<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A short booking link's code: a lead until it expires (BookingLink). */
#[Fillable(['code', 'lead_id', 'expires_at'])]
class BookingCode extends Model implements StatesRetention
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A random code standing for a lead in a booking link texted to them. '
            .'No personal data of its own; deleted with the lead.'
        );
    }
}
