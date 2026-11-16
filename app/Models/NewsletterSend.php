<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to put a newsletter in front of an audience.
 *
 * One row per SEND rather than per recipient: the tenant's provider owns
 * per-recipient delivery, so a row per address would be a stale copy of their
 * data and would invent the tracking surface nobody has decided on.
 */
class NewsletterSend extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its newsletter's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['newsletters', Newsletter::class, 'newsletter_id'];

    use HasUuids;

    /**
     * Waiting for the sweep, because its newsletter is scheduled. A send that
     * is going out NOW is created as `sending` instead — otherwise the sweep
     * would find it queued, claim it, and mail the same audience twice.
     */
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'The result of sending one newsletter: which account carried it, how many '
            .'addresses it went to, and what the provider answered. It goes when the '
            .'newsletter it belongs to goes.'
        );
    }

    protected $fillable = ['newsletter_id', 'connection_id', 'provider', 'recipients', 'status'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'started_at' => 'datetime'];
    }

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    public function markSent(?string $remoteId, int $recipients): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'remote_id' => $remoteId,
            'recipients' => $recipients,
            'failure' => null,
            'sent_at' => now(),
        ])->save();
    }

    public function markFailed(string $because): void
    {
        $this->forceFill(['status' => self::STATUS_FAILED, 'failure' => $because])->save();
    }
}
