<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What a call was about. Named so it can be counted. */
#[Fillable(['name', 'is_active', 'position'])]
class PhoneCallTag extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A label this organization counts its calls by. Removing the '
            .'organization takes the row.'
        );
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function calls(): HasMany
    {
        return $this->hasMany(PhoneCall::class, 'tag_id');
    }
}
