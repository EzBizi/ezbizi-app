<?php

namespace App\Console\Commands;

use App\Models\AutomationEvent;
use App\Models\BusinessLocation;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * The day on Google, per organization (A1 Leads takeover, 2026-10-19): what
 * changed on its listings and what its reviews did, raised as
 * `business_location.daily_digest` so a rule decides who hears it and how.
 * Nothing is raised for a quiet day.
 *
 * Read from what the syncs already recorded - the changed_on_google,
 * review.received and review.removed events - so it adds no Google calls.
 */
class GbpDailyDigest extends Command
{
    protected $signature = 'ezbizi:gbp-daily-digest';

    protected $description = 'Raise the daily Business Profile digest for each organization with a profile';

    /** Labels for the fields a listing change can carry. */
    private const FIELDS = [
        'title' => 'name', 'phone' => 'phone', 'website' => 'website', 'address_line1' => 'address',
        'city' => 'city', 'postal_code' => 'ZIP', 'primary_category' => 'category',
        'verification_state' => 'verification', 'is_published' => 'open', 'listed' => 'listed',
    ];

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $organizations = SocialConnection::withoutGlobalScopes()
            ->where('provider', SocialProvider::GoogleBusinessProfile->value)
            ->distinct()->pluck('organization_id');

        foreach ($organizations as $id) {
            $organization = Organization::withoutGlobalScopes()->find($id);
            if ($organization === null) {
                continue;
            }

            $tenant->run($organization, function () use ($events) {
                $since = now()->subDay();
                $of = fn (string $key) => AutomationEvent::query()->where('event_key', $key)->where('occurred_at', '>=', $since);

                $changes = $of('business_location.changed_on_google')->orderBy('occurred_at')->get();
                $titles = BusinessLocation::query()->whereIn('id', $changes->pluck('subject_id'))->pluck('title', 'id');
                $lines = $changes->map(function (AutomationEvent $e) use ($titles) {
                    $moved = collect((array) ($e->payload['after'] ?? []))
                        ->map(fn ($v, $k) => (self::FIELDS[$k] ?? $k).': '.$this->words($e->payload['before'][$k] ?? null).' -> '.$this->words($v))
                        ->implode('; ');

                    return ($titles[$e->subject_id] ?? 'A listing').' - '.$moved;
                })->all();

                $received = $of('review.received')->get();
                $digest = [
                    'changes'     => count($lines),
                    'new_reviews' => $received->count(),
                    'low_star'    => $received->filter(fn ($e) => (int) ($e->payload['after']['rating'] ?? 5) <= 2)->count(),
                    'removed'     => $of('review.removed')->count(),
                ];

                if (array_sum($digest) === 0) {
                    return;
                }

                $summary = array_filter([
                    $digest['new_reviews'] ? "{$digest['new_reviews']} new review(s), {$digest['low_star']} of 2 stars or fewer." : null,
                    $digest['removed'] ? "{$digest['removed']} review(s) removed by Google." : null,
                    ...($lines === [] ? [] : ['Listing changes:', ...$lines]),
                ]);

                $events->emit('business_location.daily_digest', null, ['after' => [...$digest, 'summary' => implode("\n", $summary)]]);
            });
        }

        return self::SUCCESS;
    }

    private function words(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => 'none',
            $value === true => 'yes',
            $value === false => 'no',
            default => (string) $value,
        };
    }
}
