<?php

namespace App\Support\Files;

use App\Models\Attachment;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class AttachmentUploader
{
    public function __construct(
        private readonly FileStore $files,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Persist an upload and its audit record together. Optional hooks let a
     * domain re-authorize under lock and add its own row in the same transaction.
     *
     * @param  (Closure(): Model)|null  $prepareSubject
     * @param  (Closure(Attachment, Model): void)|null  $afterPersist
     */
    public function upload(
        User $actor,
        Model $subject,
        UploadedFile $file,
        ?string $attachableType = null,
        ?Closure $prepareSubject = null,
        ?Closure $afterPersist = null,
    ): Attachment {
        $size = $file->getSize();
        $checksum = hash_file('sha256', $file->getRealPath());
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $disk = $this->files->defaultDisk();
        // Onboarding uploads hold identity documents (a Social Security card
        // photo, Merav 2026-11-09), so they are encrypted at rest.
        // A sensitive task's files likewise, and their names are sealed (SensitiveTasks).
        $sealed = $subject instanceof \App\Models\TaskUpdate && (bool) $subject->task?->sensitive;
        $encrypted = $subject instanceof \App\Models\Onboarding || $sealed;
        $directory = $this->files->directoryFor('attachments', $actor->organization_id);
        $path = $encrypted
            ? $this->files->putContents(\Illuminate\Support\Facades\Crypt::encryptString((string) file_get_contents($file->getRealPath())),
                $directory, 'enc', $disk)
            : $this->files->put($file, $directory, $disk);

        try {
            return DB::transaction(function () use (
                $actor, $subject, $file, $attachableType, $prepareSubject,
                $afterPersist, $size, $checksum, $mime, $disk, $path, $encrypted, $sealed,
            ): Attachment {
                $target = $prepareSubject === null ? $subject : $prepareSubject();
                if ($target->getAttribute('organization_id') !== $actor->organization_id) {
                    throw new RuntimeException('An attachment cannot cross organization boundaries.');
                }

                $name = mb_substr((string) $file->getClientOriginalName(), 0, 255);
                $attachment = new Attachment([
                    'attachable_type' => $attachableType ?? $target->getMorphClass(),
                    'attachable_id' => $target->getKey(),
                    'original_name' => $sealed ? \App\Support\Tasks\SensitiveTasks::LOCKED_FILE : $name,
                    'mime' => mb_substr($mime, 0, 160),
                    'uploaded_by_user_id' => $actor->getKey(),
                ]);
                $attachment->forceFill([
                    'disk' => $disk,
                    'path' => $path,
                    'size_bytes' => $size,
                    'checksum' => $checksum,
                    'encrypted' => $encrypted,
                    'sealed_name' => $sealed ? \App\Support\Tasks\SensitiveTasks::seal($name) : null,
                ])->save();

                $this->audit->log('attachment.added', $attachment, metadata: [
                    'original_name' => $attachment->original_name,
                    'mime' => $attachment->mime,
                    'size_bytes' => $attachment->size_bytes,
                    'checksum' => $attachment->checksum,
                    'attached_to' => [
                        'type' => $attachment->attachable_type,
                        'id' => $attachment->attachable_id,
                    ],
                ], actor: $actor);

                $afterPersist?->__invoke($attachment, $target);

                return $attachment->fresh('uploadedBy:id,name');
            });
        } catch (Throwable $failure) {
            $this->files->delete($path, $disk);

            throw $failure;
        }
    }
}
