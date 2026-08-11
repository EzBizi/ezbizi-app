<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * A file, and the record it belongs to.
 *
 * The row is the record; the bytes are somewhere a driver knows about. Keeping
 * `disk` beside `path` is what lets those two facts move independently — see
 * App\Support\Files\FileStore for why the app's current default disk is the
 * wrong thing to read when serving a file written months ago.
 */
class Attachment extends Model implements HoldsPersonalData
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'Files attached to records — a photograph of what was found on arrival, a '
            .'signed document, a copy of a licence. The file itself is not in the database; '
            .'this row is the index to it.';
    }

    public static function personalDataRetention(): string
    {
        return 'For as long as the record it hangs off. A soft-deleted record keeps its '
            .'files, because it can be restored; a permanently deleted one takes them, row '
            .'and bytes together, in the same transaction.';
    }

    public static function personalDataHeld(): array
    {
        return [
            /*
             * The entry §9 says is impossible to reconstruct later.
             *
             * "Uploaded files" is on its list of places personal data hides,
             * and it is the hardest one to answer honestly: nothing in the
             * schema can say whether a given PDF is a work order or a scan of
             * somebody's passport, because the person who uploaded it decided.
             * Saying so is the answer. An inventory that omitted this because it
             * could not quantify it would be the misleading kind.
             */
            new PersonalDataEntry('the file contents', PersonalDataEntry::CONTENT,
                'Whatever the person uploading chose to attach. It may be a photograph of '
                .'a person, an identity document or a signed contract — the software '
                .'cannot tell, and does not inspect it beyond checking the type and the '
                .'size. Files are served only through an authorization check, never from '
                .'a public path.',
                tenantDefined: true),
            new PersonalDataEntry('original_name', PersonalDataEntry::CONTENT,
                'What the person called the file, which often names its subject — '
                .'"marta-licence.pdf" says something even before it is opened.'),
            new PersonalDataEntry('uploaded_by_user_id', PersonalDataEntry::ACTIVITY,
                'Who attached it, so a file can be traced to the person who added it.'),
        ];
    }

    /**
     * `disk`, `path`, `checksum` and `size_bytes` are NOT fillable.
     *
     * They describe bytes the server wrote, and every one of them is decided by
     * AttachmentsController from the upload itself. A request that could set
     * `path` could name any file on the disk — including another organization's,
     * since the tenant scope filters rows and knows nothing about the filesystem
     * — and then read it back through the download endpoint, which would happily
     * authorize against the attachment's own (correct) subject.
     */
    protected $fillable = [
        'organization_id',
        'attachable_type', 'attachable_id',
        'original_name', 'mime',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /**
     * The subject gets a veto on removal, and it is enforced here as well.
     *
     * AttachmentsController asks the same question and answers a readable 422,
     * which is the right response to a person. This is the guarantee for every
     * other writer — a queued job, a cleanup command, a tinker session — because
     * the file being protected is one a signature fingerprints, and evidence
     * that can be removed by a path nobody thought about is not evidence.
     *
     * Deliberately NOT a check the subject can be given without asking for it:
     * `attachmentRemovalRefusal` is declared on HasAttachments and returns null,
     * so a record type that has no evidentiary files behaves exactly as before.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $attachment) {
            $subject = $attachment->attachable;

            $refusal = $subject !== null && method_exists($subject, 'attachmentRemovalRefusal')
                ? $subject->attachmentRemovalRefusal($attachment)
                : null;

            if ($refusal !== null) {
                throw new RuntimeException('Refusing to delete this attachment. '.$refusal);
            }
        });
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * What this row says on screen and on the wire.
     *
     * `path` and `disk` are deliberately absent. They are internal storage
     * facts, and a client that knew them would eventually try to use them —
     * which is the whole reason files are served through an endpoint rather than
     * from a URL anybody can construct.
     *
     * `checksum` IS published. It is not a secret; it is what lets somebody
     * verify that the file they downloaded is the file that was attached, which
     * is the point of storing it.
     */
    public function present(): array
    {
        return [
            'id'         => $this->id,
            'attachable' => [
                // Already the wire name, not a class: the morph map registered
                // in AppServiceProvider is what `attachable_type` is stored as.
                // Reading it off the column rather than resolving the subject
                // also means presenting a list costs no extra queries, and an
                // attachment whose subject has since gone still renders.
                'type' => $this->attachable_type,
                'id'   => $this->attachable_id,
            ],
            'original_name' => $this->original_name,
            'mime'          => $this->mime,
            'size_bytes'    => $this->size_bytes,
            'checksum'      => $this->checksum,
            'uploaded_by'   => [
                'id'   => $this->uploaded_by_user_id,
                'name' => $this->uploadedBy?->name,
            ],
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
