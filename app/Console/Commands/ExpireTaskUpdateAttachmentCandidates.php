<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\TaskUpdateAttachmentCandidate;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireTaskUpdateAttachmentCandidates extends Command
{
    protected $signature = 'ezbizi:expire-task-update-attachment-candidates {--limit=100}';

    protected $description = 'Remove expired private task-update attachment candidates';

    public function handle(FileStore $files, TenantContext $tenants): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $removed = $tenants->runWithoutTenant(function () use ($limit): array {
            return DB::transaction(function () use ($limit): array {
                $candidates = TaskUpdateAttachmentCandidate::query()
                    ->where('expires_at', '<=', now())
                    ->orderBy('expires_at')->orderBy('id')
                    ->limit($limit)
                    ->lock('for update skip locked')
                    ->get();
                $files = [];
                foreach ($candidates as $candidate) {
                    $attachment = Attachment::query()->whereKey($candidate->attachment_id)
                        ->lockForUpdate()->first();
                    $owned = $attachment !== null
                        && $attachment->organization_id === $candidate->organization_id
                        && $attachment->attachable_type === 'tasks'
                        && $attachment->attachable_id === $candidate->task_id
                        && $attachment->uploaded_by_user_id === $candidate->actor_user_id
                        && $candidate->expires_at->isPast();

                    // The FK is restrictive, so the marker goes first. A stale
                    // marker must never delete a file whose ownership changed.
                    $candidate->delete();
                    if ($owned) {
                        $files[] = ['disk' => $attachment->disk, 'path' => $attachment->path];
                        $attachment->delete();
                    }
                }

                return $files;
            });
        });

        foreach ($removed as $file) {
            $files->delete($file['path'], $file['disk']);
        }
        $this->info('Expired '.count($removed).' staged task attachment(s).');

        return self::SUCCESS;
    }
}
