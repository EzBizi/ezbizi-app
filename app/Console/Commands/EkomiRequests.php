<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\EkomiController;
use App\Models\AutomationEvent;
use App\Models\Job;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Reviews\EkomiAccount;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Automatic eKomi review requests, as A1 Tools sent them (Jose, 2026-11-02):
 * when an eKomi account's automatic requests are on, each job completed since
 * they were switched on is asked about once, the set number of hours after it
 * was completed, when its customer has an email. A refusal eKomi says is for
 * good (duplicate, unsubscribed, blacklisted) is not retried; any other is.
 */
class EkomiRequests extends Command
{
    protected $signature = 'ezbizi:ekomi-requests';

    protected $description = 'Ask eKomi for a review of each completed job, where automatic requests are on';

    public function handle(TenantContext $tenants, AuditLogger $audit): int
    {
        SocialConnection::query()->withoutGlobalScopes()->where('provider', SocialProvider::Ekomi->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)->get()
            ->filter(fn (SocialConnection $c) => EkomiController::settings($c)['auto_request'])
            ->each(fn (SocialConnection $c) => $tenants->run(Organization::query()->findOrFail($c->organization_id), fn () => $this->askFor($c, $audit)));

        return self::SUCCESS;
    }

    private function askFor(SocialConnection $c, AuditLogger $audit): void
    {
        $settings = EkomiController::settings($c);
        $until = now()->subHours($settings['delay_hours']);
        $since = Carbon::parse($settings['start_date'] ?? 'today');

        $ids = AutomationEvent::query()->where('event_key', 'job.completed')->where('subject_type', Job::class)
            ->whereBetween('occurred_at', [$since, $until])->distinct()->pluck('subject_id');
        $jobs = Job::query()->whereKey($ids)->whereNull('ekomi_requested_at')->with('customer')
            ->when($c->scope_type === SocialConnection::SCOPE_COMPANY,
                fn ($q) => $q->whereHas('franchise', fn ($f) => $f->where('company_id', $c->scope_id)))
            ->get();

        foreach ($jobs as $job) {
            // One claim per job: a second run that overlaps finds it taken.
            if (Job::query()->whereKey($job->id)->whereNull('ekomi_requested_at')->update(['ekomi_requested_at' => now()]) !== 1) {
                continue;
            }
            $email = trim((string) $job->customer?->email);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            [$first, $last] = array_pad(explode(' ', trim((string) $job->customer->name), 2), 2, '');
            try {
                $result = EkomiAccount::ask($c, (string) $job->id, $email, $first, $last);
            } catch (Throwable $e) {
                report($e);
                $result = ['ok' => false, 'never' => false, 'message' => 'eKomi did not answer.'];
            }
            if (! $result['ok'] && ! $result['never']) {
                Job::query()->whereKey($job->id)->update(['ekomi_requested_at' => null]);
            }
            $audit->log($result['ok'] ? 'ekomi.review_requested' : 'ekomi.review_request_refused', $job,
                metadata: ['message' => $result['message'], 'by' => 'automatic']);
            $this->line("{$job->id}: ".($result['ok'] ? 'asked' : $result['message']));
        }
    }
}
