<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A short review link's code: a customer and a job until it expires (CustomerLinks). */
#[Fillable(['code', 'customer_id', 'job_id', 'user_id', 'franchise_id', 'expires_at'])]
class ReviewCode extends Model implements StatesRetention
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
            'A random code standing for a customer and a job in a review link texted to them. '
            .'No personal data of its own; deleted with the customer.'
        );
    }
}
