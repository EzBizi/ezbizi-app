<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One tag change seen in a Workiz account: renamed, recoloured, added or deleted. */
#[Fillable(['franchise_id', 'tag_remote_id', 'change', 'before', 'after', 'occurred_at'])]
class WorkizTagChange extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'occurred_at' => 'datetime'];
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The history of the tag names and colours each Workiz account has used: no person in it, '
            .'only a tag\'s name and colour before and after. Kept as the record of who renamed what, when.'
        );
    }
}
