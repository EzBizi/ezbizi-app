<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
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
class Newsletter extends Model implements CarriesAutomationScope, StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'newsletters';

    use HasUuids;

    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_COMPANY      = 'company';
    public const SCOPE_FRANCHISE    = 'franchise';

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
        'subject', 'name', 'preheader', 'blocks', 'html_override', 'newsletter_audience_id',
        'created_by', 'scope_type', 'scope_id', 'status',
        'scheduled_for', 'scheduled_timezone',
    ];

    /**
     * Where this mailing sits, for a rule and for who hears about it.
     *
     * ── What this closes ────────────────────────────────────────
     *
     * `NotificationDispatcher::audienceFor` falls through to EVERY ACTIVE USER
     * in the organization when an event's subject cannot say where it is: no
     * `CarriesAutomationScope`, no `franchise_id`, no `owning_franchise_id`.
     * This model has none of those columns, so until now every
     * `newsletter.sent` and `newsletter.unsubscribed` landed in that fallback —
     * and `newsletter.unsubscribed` carries an email address in its payload.
     * A tenant may attach `notify` to any raised trigger, so one branch's
     * unsubscribe could have told the whole organization who left.
     *
     * ── The XOR reads straight across ───────────────────────────
     *
     * §3 gives this exactly one of organization, company or franchise, and the
     * two lower words are the ones `automations.scope_type` already stores, so
     * there is nothing to derive: the column IS the answer. That is the whole
     * reason this is three lines and Inspection's is thirty — an inspection has
     * to reason from a job to a customer to a franchise, and a newsletter was
     * filed against its scope when it was written.
     *
     * `[null, null]` for an organization mailing, which is honest rather than a
     * fallback: a newsletter written at the organization goes to the whole
     * organization's audience, so only an organization-wide rule sensibly
     * covers it.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function automationScope(): array
    {
        return match ($this->scope_type) {
            self::SCOPE_FRANCHISE => [self::SCOPE_FRANCHISE, (string) $this->scope_id],
            self::SCOPE_COMPANY   => [self::SCOPE_COMPANY, (string) $this->scope_id],
            default               => [null, null],
        };
    }

    protected function casts(): array
    {
        return ['blocks' => 'array', 'revision' => 'integer', 'last_test_revision' => 'integer', 'last_test_at' => 'datetime'];
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
