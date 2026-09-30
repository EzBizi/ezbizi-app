<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image a guide holds itself, in EzBizi's private storage.
 *
 * A screen references one of these by `media_id` instead of carrying a public
 * link; readers get a short-lived signed URL from
 * [App\Support\Training\TrainingMediaLinks] after their read is authorised.
 * See the migration for why this exists at all.
 */
class TrainingMedia extends Model implements StatesRetention
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'training_media';

    protected $fillable = ['guide_id', 'disk', 'path', 'sha256', 'mime', 'bytes'];

    /** The only types served inline — see TrainingMediaController. */
    public const SERVABLE = ['image/jpeg', 'image/png'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'Part of the guide that shows it. It goes when that guide is deleted, by '
            .'cascade, and has no life apart from it.'
        );
    }

    /** @return BelongsTo<TrainingGuide, $this> */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(TrainingGuide::class, 'guide_id');
    }
}
