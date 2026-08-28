<?php

namespace App\Console\Commands;

use App\Models\TaskImportSession;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireTaskImportSessions extends Command
{
    protected $signature = 'ezbizi:expire-task-import-sessions {--batch=100}';

    protected $description = 'Remove expired one-use Task import sessions, then delete their private staging bytes.';

    public function handle(FileStore $files, TenantContext $tenant): int
    {
        $batch = min(500, max(1, (int) $this->option('batch')));
        $removed = 0;
        do {
            $staged = $tenant->runWithoutTenant(fn () => DB::transaction(function () use ($batch): array {
                $sessions = TaskImportSession::withoutGlobalScopes()->where('expires_at', '<=', now())
                    ->orderBy('id')->limit($batch)->lock('for update skip locked')->get();
                $paths = $sessions->map(fn (TaskImportSession $session) => [
                    'disk' => $session->disk, 'path' => $session->path,
                ])->all();
                foreach ($sessions as $session) {
                    $session->delete();
                }

                return $paths;
            }));
            foreach ($staged as $file) {
                $files->delete($file['path'], $file['disk']);
            }
            $removed += count($staged);
        } while (count($staged) === $batch);

        $this->info("Expired {$removed} Task import session(s).");

        return self::SUCCESS;
    }
}
