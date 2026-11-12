<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
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

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'For as long as the record it hangs off. A soft-deleted record keeps its '
            .'files, because it can be restored; a permanently deleted one takes them, row '
            .'and bytes together, in the same transaction. An age-based sweep here would '
            .'delete the photograph of what was found on arrival while the job it proves is '
            .'still open.'
        );
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
                /*
                 * The one kind of personal data that cannot be anonymised in
                 * place. §9's whole resolution is "keep the record, blank the
                 * fields", and there are no fields here — the personal data is
                 * inside a PDF or a JPEG that this software has never parsed and
                 * has no business parsing. There is no third option between
                 * keeping a scan of somebody's passport and deleting it.
                 *
                 * The veto on Attachment::deleting is what makes this safe rather
                 * than reckless: a file a signature fingerprints refuses to go,
                 * and the Anonymiser reports the refusal instead of swallowing
                 * it. So evidence survives, ordinary uploads do not, and the
                 * person running the purge is told which was which.
                 */
                Erasure::drop('The contents cannot be blanked — this software has never '
                    .'looked inside the file and cannot edit somebody out of a photograph. '
                    .'A file that is evidence refuses to be removed and is reported instead; '
                    .'everything else goes, bytes and row together.'),
                tenantDefined: true),
            new PersonalDataEntry('original_name, sealed_name', PersonalDataEntry::CONTENT,
                'What the person called the file (encrypted in sealed_name on a sensitive task), which often names its subject — '
                .'"marta-licence.pdf" says something even before it is opened.',
                Erasure::drop('Goes with the file it names. Keeping the row for its '
                    .'filename alone would keep the one part of it that is readable '
                    .'without opening anything.')),
            new PersonalDataEntry('uploaded_by_user_id', PersonalDataEntry::ACTIVITY,
                'Who attached it, so a file can be traced to the person who added it.',
                Erasure::keep('A foreign key to an account, not a name. When that person is '
                    .'erased their own row is anonymised at its one home, and this key then '
                    .'points at a record with no name in it — so the provenance of somebody '
                    .'else\'s file survives without identifying anybody.')),
        ];
    }

    /**
     * Files that are ABOUT this person — theirs, and their jobs'.
     *
     * The uploader link is deliberately not part of this. A photograph of a job
     * site taken by somebody who has since left is that customer's record, not
     * the photographer's, and returning it here would delete another person's
     * evidence in the name of erasing this one.
     *
     * Reaching through jobs is what makes a customer purge complete rather than
     * nearly complete. §9 names uploaded files among the places a purge misses,
     * and the way it misses them is exactly this: the obvious query — files hung
     * directly off the customer — finds none of the ones that matter, because
     * photographs get attached to the visit rather than to the person.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        /*
         * `getMorphClass()`, never `::class`. `attachable_type` stores the wire
         * name from the morph map registered in AppServiceProvider — see
         * `present()` — so comparing against a fully-qualified class name matches
         * nothing at all, silently, and the purge would report a clean sweep of a
         * table it never looked in.
         *
         * The whole disjunction is wrapped in ONE closure, so that the builder
         * handed back is safe for a caller to narrow — see
         * ContractSignature::personalDataAbout, where that is argued at length.
         */
        return static::query()->where(function (Builder $query) use ($subject) {
            $query->where(fn (Builder $q) => $q
                ->where('attachable_type', $subject->getMorphClass())
                ->where('attachable_id', $subject->getKey()));

            if ($subject instanceof Customer) {
                /*
                 * `withTrashed()`, and it is the whole point of this branch.
                 *
                 * Job soft-deletes, so the sub-builder compiles with
                 * `deleted_at is null` and the photographs attached to a
                 * deleted-but-restorable visit are never found — which is the
                 * exact shape of miss HoldsPersonalData::personalDataAbout
                 * forbids ("a purge that skipped them would leave the exact rows
                 * somebody is asking about"). A trashed job is a job the
                 * organization can still restore, so its files are still held.
                 */
                $query->orWhere(fn (Builder $q) => $q
                    ->where('attachable_type', (new Job)->getMorphClass())
                    ->whereIn('attachable_id', Job::query()
                        ->withTrashed()
                        ->where('customer_id', $subject->getKey())
                        ->select('id')));
            }

            /*
             * And a customer's photographs also hang off their INSPECTIONS.
             *
             * The report declares `answers` erasable and blanks it to `[]`, so
             * the sentence naming the picture goes — but `answers` holds the
             * attachment's id, not its bytes. Without this branch the blanking
             * is the whole of the erasure and the photograph of the house is
             * still on disk with nothing left pointing at it, which is §9's
             * "uploaded files" miss exactly.
             *
             * Only reports. A run is a personal draft that never outlives a
             * submit or a discard, and its own retention says so.
             */
            if ($subject instanceof Customer) {
                $query->orWhere(fn (Builder $q) => $q
                    ->where('attachable_type', (new InspectionReport)->getMorphClass())
                    ->whereIn('attachable_id', InspectionReport::query()
                        ->where('customer_id', $subject->getKey())
                        ->select('id')));
            }

            /*
             * An employee's files hang off their ONBOARDING, not off them.
             *
             * The rendered contract is the case that matters, and it is the one
             * the refusal above exists for: it contains every answer they gave,
             * sensitive ones included, in the clear, because it is the thing that
             * was signed. Reaching it here is what makes the purge report say
             * "this could not be removed, and here is why" instead of never
             * looking. Onboarding refuses it, the refusal is printed, and
             * somebody reading the report learns that the signed document
             * survives — which is true, and is not something they should have to
             * find out from a lawyer.
             */
            if ($subject instanceof User) {
                $query->orWhere(fn (Builder $q) => $q
                    ->where('attachable_type', (new Onboarding)->getMorphClass())
                    ->whereIn('attachable_id', Onboarding::query()
                        ->where('user_id', $subject->getKey())
                        ->select('id')));
            }
        });
    }

    /**
     * The bytes this row is the index to.
     *
     * Deleting the row and leaving the file is the failure this exists to stop —
     * the app forgets where the identity document is and the identity document is
     * still there. AttachmentsController::destroy does the same two steps in the
     * same order for the same reason; this is how every OTHER caller gets it.
     *
     * @return array<array{disk: ?string, path: ?string}>
     */
    public function personalDataFiles(): array
    {
        return [['disk' => $this->disk, 'path' => $this->path]];
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
        // 'before' or 'after': which side of the work a photo shows.
        'label',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'encrypted' => 'boolean'];
    }

    /**
     * What the person called the file. A sensitive task's file is called
     * "Locked file" in original_name and its real name is sealed; only a
     * caller that has passed SensitiveTasks::reveal asks for this.
     */
    public function realName(): string
    {
        return $this->sealed_name !== null
            ? (string) \App\Support\Tasks\SensitiveTasks::open($this->sealed_name)
            : (string) $this->original_name;
    }

    /** The file's bytes, decrypted when stored encrypted; null when it has gone. */
    public function bytes(): ?string
    {
        $stored = app(\App\Support\Files\FileStore::class)->contents($this->path, $this->disk);

        return $stored !== null && $this->encrypted ? \Illuminate\Support\Facades\Crypt::decryptString($stored) : $stored;
    }

    /**
     * Why this file cannot be removed, or null if it can.
     *
     * ── Askable before the act, not only during it ───────────────
     *
     * This used to live entirely inside the `deleting` hook below, as an
     * exception. That serves anything DELETING a row and is useless to anything
     * PLANNING to: the Anonymiser's dry run walked this table, counted the
     * rendered contract as removable and printed a preview promising to destroy
     * the one file the real run then refused to touch. A preview that reports
     * the opposite of the truth on the only interesting case is worse than no
     * preview, because it is what somebody reads before answering a rights
     * request.
     *
     * So the question is separable from the act, and there is still one home for
     * the answer: `deleting` throws on it, AttachmentsController turns it into a
     * 422, and App\Support\Privacy\Anonymiser reads it — which is what makes the
     * preview and the purge describe one operation rather than two.
     *
     * Deliberately NOT a check the subject can be given without asking for it:
     * `attachmentRemovalRefusal` is declared on HasAttachments and returns null,
     * so a record type that has no evidentiary files behaves exactly as before.
     */
    public function deletionRefusal(): ?string
    {
        $subject = $this->attachable;

        return $subject !== null && method_exists($subject, 'attachmentRemovalRefusal')
            ? $subject->attachmentRemovalRefusal($this)
            : null;
    }

    /**
     * The subject's veto, enforced here as well.
     *
     * AttachmentsController asks the same question and answers a readable 422,
     * which is the right response to a person. This is the guarantee for every
     * other writer — a queued job, a cleanup command, a tinker session — because
     * the file being protected is one a signature fingerprints, and evidence
     * that can be removed by a path nobody thought about is not evidence.
     */
    protected static function booted(): void
    {
        // A Task Board video gets a poster frame for the Gallery (Jose 2026-11-12).
        static::created(function (self $attachment) {
            if (\App\Jobs\MakeVideoPoster::wants($attachment)) {
                \App\Jobs\MakeVideoPoster::dispatch($attachment->getKey())->afterCommit();
            }
        });

        static::deleting(function (self $attachment) {
            $refusal = $attachment->deletionRefusal();

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
            'label'         => $this->label,
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
