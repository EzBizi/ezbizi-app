<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One company named on a phone service request, and the answer for it.
 *
 * `approved` is null until somebody decides, true for yes, false for no. This
 * row IS the entitlement — see [PhoneServiceRequest::approvedFor].
 */
#[Fillable(['request_id', 'company_id', 'approved'])]
class PhoneServiceRequestCompany extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'phone_service_request_companies';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A company named on a phone service request. Removing the request takes the row.'
        );
    }

    protected function casts(): array
    {
        return ['approved' => 'boolean'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PhoneServiceRequest::class, 'request_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
