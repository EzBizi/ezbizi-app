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
}
