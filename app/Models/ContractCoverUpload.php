<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WrittenOnce;
use App\Support\Files\FileStore;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A cover image somebody uploaded for a contract, before any version names it.
 *
 * ── Why this is its own row and not an attachment ────────────────
 *
 * An `Attachment` points AT the record it belongs to, and at the moment a cover
 * is chosen there may be no record to point at: a contract being CREATED has no
 * id yet, so a cover that could only be uploaded against an existing contract
 * would be a cover you could add on the second visit and not the first. The
 * upload endpoint is therefore `POST hr/contracts/covers` — no binding — and
 * this row is what it answers with.
 *
 * ── Written once, and kept ───────────────────────────────────────
 *
 * A published version names the upload it came from, so this row is part of the
 * provenance of a document somebody signed: "this front page is the file
 * Marie uploaded on the 3rd" is a question it answers and nothing else does.
 * That is also why there is no sweep of unpublished stragglers here. A cover
 * chosen and then abandoned costs one small PNG; a sweep that could not tell an
 * abandoned one from one a version names would eventually take the second kind.
 *
 * ── It is not what renders the document ──────────────────────────
 *
 * The version copies the bytes into a file of its OWN at publish
 * (ContractVersion::hasCover) and renders from that. This row is what the
 * DESIGNER draws — the thumbnail beside "Change it" — and what the wire calls
 * `upload_id`. Two copies of a small image, deliberately: the instrument's copy
 * must not depend on a staging area, and the staging area must stay answerable
 * for what was uploaded.
 */
class ContractCoverUpload extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use WrittenOnce;

    public const UPDATED_AT = null;   // staged covers are written once

    protected $table = 'contract_cover_uploads';

    protected $fillable = [
        'organization_id', 'path', 'disk', 'original_name', 'size_bytes',
        'uploaded_by_user_id',
    ];

    /**
     * Never on a clock, for the same reason a version is not.
     *
     * An age-based window would delete the oldest upload first — which is the
     * one the longest-standing contract's cover was taken from, and the one
     * whose provenance is hardest to reconstruct afterwards.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept for as long as the organization exists. A published contract version names '
            .'the upload its cover came from, so removing one would leave a signed document '
            .'unable to say where its front page was taken from.'
        );
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'A staged contract cover is written once. A version names the upload its '
                .'front page came from, so editing this row would rewrite the provenance of '
                .'a document somebody has already signed. Upload the new image instead — it '
                .'is a new row, and using it is a new version.'
            );
        });
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * The bytes, or null when the file has gone.
     *
     * Null rather than an exception for the reason FileStore::contents gives:
     * `storage/` is outside the nightly backup, so a rebuilt instance has the
     * rows and not the files, and a caller answering 404 is more use than a
     * stack trace.
     */
    public function png(): ?string
    {
        return app(FileStore::class)->contents($this->path, $this->disk);
    }
}
