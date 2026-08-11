<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Contracts\DocumentFingerprint;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\WrittenOnce;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One rendered revision of an onboarding's contract document.
 *
 * Revision 1 is what was signed. Everything after it is an amendment, and both
 * stay — the plan's rule, in the schema rather than in a convention: "The
 * original stays because it is what was signed; the amendment is what is true."
 *
 * ── The row is the record; the bytes are an attachment ───────────
 *
 * §11's primitive, not a second one. The file goes through FileStore onto
 * whatever disk is configured, `attachments` remembers which disk that was, and
 * this row says what the file MEANS: which revision, why it exists, and the
 * fingerprint of exactly the bytes stored.
 *
 * ── The fingerprint is over the STORED bytes, deliberately ───────
 *
 * This is the trap the plan warns about — "a fingerprint over one representation
 * and a document stored in another, because then the hash proves nothing about
 * the file". So the order is: render, store, read the stored file back, hash
 * that. Not: render, hash the string in memory, store something derived from it.
 * The two are usually identical and the day they are not is the day the hash
 * would have been worthless.
 */
class OnboardingDocument extends Model
{
    /*
     * WRITTEN ONCE, at the query builder too — not only at the model.
     *
     * This had `updating` and `deleting` model events, which is exactly the hole
     * WrittenOnce was written last week to close on the row sitting next to it.
     * Model events never fire for `Model::query()->update()`, and the
     * consequence here is not academic: tampering with the stored HTML and then
     * rewriting content_hash in one statement made the document report
     * `intact: true` while serving altered terms, and the second party's
     * signature was accepted over the forgery.
     *
     * A rendered document is evidence in the same way a signature is, and gets
     * the same protection rather than a weaker imitation of it.
     */
    use WrittenOnce;

    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;   // written once

    /** Revision 1: the agreement as it was when the first party signed it. */
    public const ORIGINAL = 1;

    protected $fillable = [
        'organization_id', 'onboarding_id', 'attachment_id', 'revision',
        'reason', 'changed_keys', 'content_hash', 'hash_algorithm', 'content_length',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'changed_keys'   => 'array',
            'content_length' => 'integer',
            'revision'       => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Immutable, and for the same reason a signature is.
         *
         * A signature's `content_hash` points at one of these. If this row could
         * be edited, the comparison a dispute turns on would be against a value
         * somebody could have moved afterwards — which makes the tamper-evidence
         * evidence of nothing. Amending produces a NEW revision; that is what
         * "keep both" means.
         */
        static::updating(function () {
            throw new RuntimeException(
                'A rendered document cannot be edited. Correcting an onboarding produces a '
                .'new revision beside this one — the original stays, because it is what was '
                .'signed.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'A rendered document cannot be deleted. It is the artefact a signature '
                .'fingerprints, and a signature whose document can disappear proves nothing. '
                .'An organization being removed takes its documents with it; nothing else '
                .'does.'
            );
        });
    }

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOriginal(): bool
    {
        return $this->revision === self::ORIGINAL;
    }

    /**
     * Are these still the bytes this revision recorded?
     *
     * The same call a signature makes, asked of the document instead. Read
     * DocumentFingerprint's docblock before relying on a `true` for anything
     * beyond "these bytes are unchanged" — it is deliberately narrow about what
     * that is worth.
     */
    public function stillMatches(string $content): bool
    {
        return DocumentFingerprint::matches(
            $content,
            (string) $this->content_hash,
            (string) $this->hash_algorithm,
        );
    }

    public function present(): array
    {
        return [
            'id'            => $this->id,
            'revision'      => $this->revision,
            'is_original'   => $this->isOriginal(),
            'reason'        => $this->reason,
            'changed_keys'  => $this->changed_keys ?? [],
            'content_hash'  => $this->content_hash,
            'hash_algorithm' => $this->hash_algorithm,
            'content_length' => $this->content_length,
            'attachment_id' => $this->attachment_id,
            'created_by'    => $this->createdBy?->name,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
