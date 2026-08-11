<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use App\Support\Files\FileStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A record that can carry files.
 *
 * Adding this trait and a row in App\Support\Files\Attachables is the whole of
 * making a record type attachable — one declares the relation, the other says
 * the wire name and inherits the policy.
 *
 * ── Why the delete hook is here and not in a controller ──────────
 *
 * A deleted record whose files stay on disk is a §9 problem, not an untidiness:
 * the bytes are personal data that survives the erasure of the thing that
 * justified holding them, and nothing left in the database points at them, so
 * nobody will ever find them again to remove them. Putting the cleanup in the
 * controller means every future delete path — an automation action, a cascade,
 * an import rollback — has to remember it. This is the model's own event, so
 * they cannot forget.
 */
trait HasAttachments
{
    public static function bootHasAttachments(): void
    {
        static::deleting(function (Model $model) {
            /*
             * A SOFT delete keeps everything.
             *
             * `deleting` fires for both, and the distinction is the whole
             * question: a soft-deleted job is a job that still exists and can be
             * restored, and destroying its photographs would make the restore a
             * lie. §9's own resolution of this tension says the same thing from
             * the other side — soft-delete is for the audit-significant records,
             * and erasure is a separate, deliberate operation.
             *
             * `isForceDeleting()` exists only on models using SoftDeletes, so
             * the check is written as "does this model soft-delete at all"
             * first. A hard-deleting model has no second phase to wait for.
             */
            if (in_array(SoftDeletes::class, class_uses_recursive($model), true)
                && ! $model->isForceDeleting()) {
                return;
            }

            $files = app(FileStore::class);

            // One at a time, and it has to be: each row names its own disk, so
            // there is no single delete that can remove the bytes. A mass
            // `$model->attachments()->delete()` is one query and leaves every
            // file on disk with nothing left pointing at it — which is the exact
            // outcome this hook exists to prevent.
            $model->attachments()->get()->each(function (Attachment $attachment) use ($files) {
                $files->delete($attachment->path, $attachment->disk);
                $attachment->delete();
            });
        });
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * Is there a reason THIS record will not give up THAT file?
     *
     * Null for almost everything, which is why the default lives here: a photo
     * on a job is a file somebody attached and can unattach, and no subject
     * should have to say so.
     *
     * The exception is a file that is not an upload but a RECORD — a rendered
     * contract, fingerprinted by a signature. Removing one leaves the signature
     * pointing at a document nobody can produce, which quietly turns the
     * evidence into an assertion. The subject is the only thing that knows which
     * of its files those are, and asking it is what keeps AttachmentsController
     * from having to learn about contracts.
     */
    public function attachmentRemovalRefusal(Attachment $attachment): ?string
    {
        return null;
    }

    /**
     * Is READING this file a disclosure of sensitive data?
     *
     * The same shape as the refusal above, and it exists for the same reason:
     * only the subject knows what is in its own files.
     *
     * ── The hole this closes ─────────────────────────────────────
     *
     * The rendered onboarding contract is stored as an ordinary attachment and
     * deliberately contains every sensitive answer in the clear — it is the
     * thing being signed, so it cannot be masked. The document ENDPOINT was
     * taught to demand the reveal permission and write an audit entry. This one
     * was not, and it serves the same bytes:
     *
     *     GET /hr/onboardings/{id}/answers/iban  -> 403
     *     GET /hr/onboardings/{id}/document      -> 403   (fixed earlier)
     *     GET /attachments/{id}                  -> 200, the IBAN in the bytes
     *
     * Two doors to one room, and only one of them was locked. That is the
     * pattern this whole audit kept finding, and the fix belongs here rather
     * than in the controller so the NEXT subject holding sensitive files
     * inherits it.
     *
     * Returning a key list means "this is a reveal": the caller must be allowed
     * to reveal, and the read is recorded. Null means an ordinary file.
     *
     * @return string[]|null the sensitive field keys this file discloses
     */
    public function attachmentDisclosesSensitive(Attachment $attachment): ?array
    {
        return null;
    }
}
