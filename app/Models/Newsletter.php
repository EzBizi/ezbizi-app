<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A mailing: what it says, who it is for, and when it goes.
 *
 * The rendered HTML is not stored beside the blocks as a cache — it is derived
 * on the way out, so the two cannot disagree about what the mailing says.
 * `html_override` is the one exception, and it exists because rebuilding a
 * hand-edited block from its fields would silently throw the edit away.
 */
class Newsletter extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_FAILED    = 'failed';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A newsletter the organization wrote: the subject, the blocks it is built from, '
            .'who it was addressed to and when it went. It is the organization’s own marketing '
            .'output rather than anybody’s personal data, and it is kept for as long as they '
            .'want the record of what they said.'
        );
    }

    protected $fillable = [
        'subject', 'blocks', 'html_override', 'newsletter_audience_id',
        'created_by', 'scope_type', 'scope_id', 'status',
        'scheduled_for', 'scheduled_timezone',
    ];

    protected function casts(): array
    {
        return ['blocks' => 'array'];
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(NewsletterAudience::class, 'newsletter_audience_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(NewsletterSend::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Is this mailing's moment here yet?
     *
     * Resolved fresh against the clock it was promised on, never compared to a
     * stored instant. Identical to the scheduled-social-post rule and for the
     * same reason — see `2026_08_28_210000_add_scheduling_to_social_posts`.
     */
    public function isDue(): bool
    {
        if ($this->scheduled_for === null || $this->scheduled_for === '') {
            return true;
        }

        return $this->dueAt()?->lte(now()) ?? true;
    }

    public function dueAt(): ?Carbon
    {
        if ($this->scheduled_for === null || $this->scheduled_for === '') {
            return null;
        }

        return Carbon::parse(
            (string) $this->scheduled_for,
            (string) ($this->scheduled_timezone ?: config('app.timezone')),
        );
    }
}
