<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One post, at one account, and what happened.
 *
 * Three states and no fourth. `queued` means the job has not run; `sent` means
 * the network took it and gave back an id; `failed` means it refused, and
 * `failure` holds the sentence to show. There is deliberately no `sending` —
 * nothing reads it, and a row stuck in it after a crash would be a state
 * nothing can leave.
 */
class SocialPostTarget extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /**
     * Waiting for its time. Distinct from `queued`, which means the job is on
     * its way — a screen that showed "waiting to send" for a post due next
     * Tuesday would be the same lie in a slower form.
     */
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Published, then taken down from the network from EzBizi. */
    public const STATUS_REMOVED = 'removed';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'The result of sending one post to one connected account. It goes when the post '
            .'it belongs to goes, and when the account is disconnected.'
        );
    }

    protected $fillable = ['social_post_id', 'social_connection_id', 'status', 'options'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'options' => 'array'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'social_connection_id');
    }

    public function markSent(string $remoteId): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'remote_id' => $remoteId,
            'failure' => null,
            'sent_at' => now(),
        ])->save();
    }

    public function markFailed(string $because): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'failure' => $because,
        ])->save();
    }
}
