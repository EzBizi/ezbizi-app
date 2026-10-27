<?php

namespace App\Console\Commands;

use App\Models\LeadConversation;
use App\Models\Organization;
use App\Support\Leads\LeadRecordings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Every ten minutes: copy each new Google Local Services call recording to EzBizi's own disk
 * (LeadRecordings, Jose 2026-10-27), then delete any kept file whose row is
 * gone or no longer names it: an enquiry deleted by the database's cascade
 * fires no model event, so this is what keeps "deleted with the enquiry" true.
 *
 * A recording that cannot be fetched is left for the next run; play still
 * tries it live.
 */
class LeadsKeepRecordings extends Command
{
    protected $signature = 'ezbizi:leads-keep-recordings {--days=3 : calls from this many days back} {--all : every call with a recording}';

    protected $description = 'Copy lead call recordings to EzBizi\'s storage and delete the files of rows that are gone';

    public function handle(TenantContext $tenant, LeadRecordings $recordings): int
    {
        $kept = 0;
        $failed = 0;

        $rows = LeadConversation::query()->withoutGlobalScopes()
            ->where('recording_url', 'like', 'http%')->whereNull('recording_path')   // Google's only (LeadRecordings::keeps)
            ->when(! $this->option('all'), fn ($q) => $q->where('occurred_at', '>=', now()->subDays((int) $this->option('days'))))
            ->orderBy('occurred_at')
            ->get();

        foreach ($rows->groupBy('organization_id') as $organizationId => $group) {
            $organization = Organization::query()->withoutGlobalScopes()->find($organizationId);

            if ($organization === null) {
                continue;
            }

            $tenant->run($organization, function () use ($group, $recordings, &$kept, &$failed) {
                foreach ($group as $row) {
                    try {
                        $recordings->keep($row);
                        $kept++;
                    } catch (RuntimeException) {
                        $failed++;
                    }
                }
            });
        }

        $swept = $this->sweep();
        $this->info("Kept {$kept} recording(s); {$failed} could not be fetched; deleted {$swept} orphaned file(s).");

        return self::SUCCESS;
    }

    private function sweep(): int
    {
        $disk = Storage::disk('local');
        $files = $disk->allFiles(LeadRecordings::DIR);
        $named = collect(array_chunk($files, 1000))
            ->flatMap(fn (array $chunk) => LeadConversation::query()->withoutGlobalScopes()->whereIn('recording_path', $chunk)->pluck('recording_path'))
            ->flip();
        $orphans = array_values(array_filter($files, fn (string $f) => ! isset($named[$f])));
        $disk->delete($orphans);

        return count($orphans);
    }
}
