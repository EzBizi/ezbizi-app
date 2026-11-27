<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Social\Publishing\PostPayload;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Something a tenant wrote, and the file they attached to it.
 *
 * The record of what was sent, not a copy of what the network now shows. If
 * somebody edits the caption on Pinterest afterwards, this row keeps saying
 * what EzBizi published — which is the useful answer when the question is
 * "what did we put out", and the network's own page is the answer when the
 * question is "what is up there now".
 */
class SocialPost extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). Its place is its targets' pages, so the feed asks which a reader reaches (ChangeFeed::NARROW). */
    protected static string $changeType = 'social_posts';

    /** Filed on no one place: a post goes to pages at several (ChangeFeed::readableSocialPosts). */
    public function changeFeedPlace(): array
    {
        return [null, null];
    }

    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A post the organization published to its own social accounts: the words, the '
            .'image or video, who sent it, and what each network answered. It is the '
            .'organization’s own marketing output rather than anybody’s personal data, and '
            .'it is kept for as long as the organization wants the record of what it said.'
        );
    }

    protected $fillable = [
        'body', 'title', 'media_path', 'media_disk', 'created_by',
        'scheduled_for', 'scheduled_timezone',
    ];

    /**
     * Is this post's moment here yet?
     *
     * Resolved fresh rather than compared to a stored instant: the wall time is
     * the promise, and `Carbon::parse($wall, $zone)` is what turns it into a
     * moment using the clock as it stands NOW. See the migration for why that
     * matters more than an indexable column would.
     *
     * A post with no schedule is due immediately — "Publish now" is the absence
     * of a time, not a special case.
     */
    public function isDue(): bool
    {
        if ($this->scheduled_for === null || $this->scheduled_for === '') {
            return true;
        }

        return \Illuminate\Support\Carbon::parse(
            (string) $this->scheduled_for,
            (string) ($this->scheduled_timezone ?: config('app.timezone')),
        )->lte(now());
    }

    /** The moment this is promised for, as an instant, or null when immediate. */
    public function dueAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->scheduled_for === null || $this->scheduled_for === '') {
            return null;
        }

        return \Illuminate\Support\Carbon::parse(
            (string) $this->scheduled_for,
            (string) ($this->scheduled_timezone ?: config('app.timezone')),
        );
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The post as a publisher wants it: words, and a path on this filesystem.
     *
     * ── Why the file is copied to a temp path ────────────────────
     *
     * The publishers take a path because that is what they were written and
     * proved against, and the disk underneath may be S3 tomorrow — FileStore's
     * whole point is that `path()` is not part of the abstraction. Streaming to
     * a temp file is what makes both true at once.
     *
     * ponytail: whole file in memory, which is what `put` does anyway. TikTok's
     * chunked upload reads it back in pieces, so if a tenant ever uploads a
     * video big enough to matter, stream it instead of `get()`.
     *
     * The caller deletes what comes back. It is the caller and not this method
     * because the file has to outlive the call that made it.
     *
     * @return array{0: PostPayload, 1: string|null} the payload, and the temp
     *                                               path to delete afterwards
     */
    public function payload(): array
    {
        if ($this->media_path === null) {
            return [new PostPayload(body: (string) $this->body, title: $this->title), null];
        }

        $extension = pathinfo((string) $this->media_path, PATHINFO_EXTENSION);
        $temp      = tempnam(sys_get_temp_dir(), 'ezbizi-social-');

        // tempnam() makes a file with no extension, and PostPayload reads the
        // kind and the MIME type off one. Renaming rather than appending keeps
        // exactly one file behind for the caller to delete.
        if ($extension !== '') {
            $named = $temp.'.'.$extension;
            rename($temp, $named);
            $temp = $named;
        }

        file_put_contents(
            $temp,
            Storage::disk($this->media_disk ?: config('filesystems.default'))->get($this->media_path),
        );

        return [
            new PostPayload(body: (string) $this->body, filePath: $temp, title: $this->title),
            $temp,
        ];
    }
}
