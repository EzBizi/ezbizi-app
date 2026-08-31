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
#[Fillable(['name', 'color', 'is_active', 'position'])]
class PhoneCallTag extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /**
     * The colours a tag may take.
     *
     * ── Why a fixed set and not a colour picker ─────────────────
     *
     * A tag is read on a dark call list and a light report, so a colour has to
     * work on both. A free picker hands somebody pale yellow on white, and the
     * tag they chose to make legible becomes the one nobody can read. These
     * eight are checked against both surfaces.
     *
     * Stored as hex, matching what Tasks already does for board colours — one
     * convention for user-chosen colour rather than a second one. This is data
     * a customer picked, not app chrome, so it does not go through theme.dart.
     *
     * @var list<string>
     */
    public const COLORS = [
        '#E5484D', // red — complaints, problems
        '#E8912A', // amber — needs chasing
        '#F5C518', // yellow
        '#46A758', // green — booked, won
        '#12A594', // teal
        '#00AEEF', // cyan, the EzBizi brand tone
        '#3E63DD', // blue
        '#8E4EC6', // purple
    ];

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
