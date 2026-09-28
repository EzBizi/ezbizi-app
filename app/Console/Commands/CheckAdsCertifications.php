<?php

namespace App\Console\Commands;

use App\Models\AdsAccountState;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Ads\LsaCertifications;
use App\Support\Automations\EventEmitter;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every connected Google Ads account's Local Services certification, checked
 * once a morning - and `ads.certification_problem` raised when an account's
 * problems CHANGE, so a rule can tell somebody before the phone goes quiet.
 *
 * Raised on a change, not every morning: a lapse that nobody has fixed yet is
 * not news on day two, and a rule that notified daily would be switched off by
 * the people it was written for. A new problem, or a different set of them, is
 * news; the same set again is not; none is the end of it.
 */
class CheckAdsCertifications extends Command
{
    protected $signature = 'ezbizi:ads-certifications {--connection= : one connection id}';

    protected $description = 'Check the Local Services licence, insurance and background-check standing of every connected Google Ads account';

    public function handle(LsaCertifications $certifications, TenantContext $tenant, EventEmitter $events): int
    {
        $connections = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())
            ->get()->keyBy(fn (Organization $o) => $o->getKey());

        $checked = $failed = $raised = 0;

        foreach ($connections as $connection) {
            $organization = $organizations->get($connection->organization_id);
            if ($organization === null) {
                continue;
            }

            $tenant->run($organization, function () use ($connection, $certifications, $events, &$checked, &$failed, &$raised) {
                $state = AdsAccountState::query()->firstOrNew(['connection_id' => $connection->getKey()]);

                try {
                    $verdict = $certifications->check($connection);
                } catch (RuntimeException $e) {
                    // The last verdict stands; the screen says it is old and why.
                    $state->fill(['certification_failed' => mb_substr($e->getMessage(), 0, 300)])->save();
                    $failed++;

                    return;
                }

                $before = self::signature((array) ($state->certification_problems ?? []));
                $after = self::signature($verdict['problems']);

                DB::transaction(function () use ($state, $verdict, $before, $after, $connection, $events, &$raised) {
                    $state->fill([
                        'certification_statuses' => $verdict['statuses'],
                        'certification_documents' => $verdict['documents'],
                        'certification_problems' => $verdict['problems'],
                        'certification_problem_count' => count($verdict['problems']),
                        'certification_soonest_expiry' => $verdict['soonest_expiry'],
                        'certification_checked_at' => now(),
                        'certification_failed' => null,
                    ])->save();

                    if ($after !== '' && $after !== $before) {
                        $events->emit('ads.certification_problem', $state, [
                            'account' => $connection->label ?: $connection->display_name,
                            'customer_id' => $connection->remote_id,
                            'problem_count' => count($verdict['problems']),
                            'problems' => implode(' ', array_column($verdict['problems'], 'text')),
                            'soonest_expiry' => $verdict['soonest_expiry'],
                        ]);
                        $raised++;
                    }
                });
                $checked++;
            });
        }

        $this->info("Checked {$checked} account(s); {$failed} could not be read; {$raised} raised.");

        return self::SUCCESS;
    }

    /** @param list<array{code: string}> $problems */
    private static function signature(array $problems): string
    {
        $codes = array_column($problems, 'code');
        sort($codes);

        return implode('|', $codes);
    }
}
