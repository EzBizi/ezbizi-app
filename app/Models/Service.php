<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A named service this company sells (spec). */
#[Fillable([
    'company_id', 'franchise_id', 'number', 'name', 'description',
    'price_minor', 'group_id', 'label_id',
])]
class Service extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'What this organization offered as work, and at what price. Removing '
            .'the organization takes the row. Empty mistakes can still be deleted.'
        );
    }

    /** @use HasFactory<ServiceFactory> */
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES): filed where its company and branch are. */
    protected static string $changeType = 'services';

    use HasAttachments;
    use HasFactory;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServiceGroup::class, 'group_id');
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(ServiceLabel::class, 'label_id');
    }
}
