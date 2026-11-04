<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One entry of a business's own list. See ListRegistry. */
#[Fillable(['organization_id', 'list_key', 'item_key', 'value', 'sort', 'active'])]
class BusinessListItem extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'How this business is set up (its own lists of names, labels and '
            .'mappings), not a record of anything that happened. It stays as long '
            .'as the organization does.'
        );
    }

    protected function casts(): array
    {
        return ['value' => 'array', 'active' => 'boolean', 'sort' => 'integer'];
    }
}
