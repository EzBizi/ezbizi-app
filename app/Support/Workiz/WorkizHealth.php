<?php

namespace App\Support\Workiz;

use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Models\WorkizTeamMember;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialProvider;
use Illuminate\Support\Facades\Artisan;

/**
 * A Workiz account's whole setup, done on Connect and checked as pass/fail for
 * its card (Jose, 2026-11-12: "doing a connection should take care of the
 * whole process, I should not have to ask you").
 */
final class WorkizHealth
{
    /** Everything a new account needs, run once at Connect: its lists, its team, then the check. */
    public static function setUp(SocialConnection $connection): array
    {
        if (WorkizV2::uses($connection)) {
            Artisan::call('ezbizi:sync-workiz-records', ['--connection' => $connection->getKey(), '--type' => WorkizRecords::LISTS]);
        }

        Artisan::call('ezbizi:sync-workiz-team', ['--connection' => $connection->getKey()]);

        $steps = self::check($connection, fix: true);
        // ponytail: kept from the last Connect; re-run on reconnect. A scheduled re-check when the card must stay live.
        \Illuminate\Support\Facades\Cache::forever(self::cacheKey($connection), ['checked_at' => now()->toIso8601String(), 'steps' => $steps]);

        return $steps;
    }

    public static function cacheKey(SocialConnection $connection): string
    {
        return 'workiz.health.'.$connection->getKey();
    }

    /**
     * Each step as {key, label, ok, detail}. Read from what EzBizi holds, so the
     * card can show it on every load; `fix` also does what EzBizi can do itself.
     *
     * @return list<array{key: string, label: string, ok: bool, detail: string}>
     */
    public static function check(SocialConnection $connection, bool $fix = false): array
    {
        $branch = $connection->scope_id;
        $v2 = WorkizV2::uses($connection);
        $credentials = (array) ($connection->credentials ?? []);
        $tags = WorkizRecord::query()->where('franchise_id', $branch)->where('type', 'tag')->get(['payload']);
        $org = \App\Models\Organization::query()->find($connection->organization_id);
        $settings = app(SettingsResolver::class);
        $standard = collect((array) $settings->get('workiz.tag_baseline', $org))->filter('is_array');

        // No standard yet: this account's tags become it, as "Approve current tags" would.
        if ($fix && $v2 && $standard->isEmpty() && $tags->isNotEmpty() && $org !== null) {
            $standard = $tags->map(fn ($t) => WorkizTags::face($t->payload))->filter(fn ($t) => trim($t['name']) !== '')->values();
            $settings->set('workiz.tag_baseline', $org, $standard->all());
        }

        $key = fn (string $n) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $n)));
        $held = $tags->mapWithKeys(fn ($t) => [$key($t->payload['name'] ?? '') => WorkizTags::face($t->payload)]);
        $missing = $standard->reject(fn ($t) => isset($held[$key($t['name'] ?? '')]))->pluck('name');
        $login = SocialConnection::query()->where('provider', SocialProvider::WorkizWeb->value)->where('status', 'connected')->first();
        $jobs = WorkizJob::query()->where('franchise_id', $branch)->count();

        $steps = [
            ['key' => 'key', 'label' => 'API key works', 'ok' => $connection->status === 'connected',
                'detail' => $connection->status === 'connected' ? ($v2 ? 'v2 key' : 'v1 key') : (string) $connection->status_reason],
            ['key' => 'writes', 'label' => 'EzBizi can write to Workiz', 'ok' => $v2 || filled($credentials['auth_secret'] ?? null),
                'detail' => $v2 || filled($credentials['auth_secret'] ?? null) ? 'Yes' : 'Add the v1 Auth Secret, or connect a v2 key.'],
            ['key' => 'tags', 'label' => 'Tag list read', 'ok' => ! $v2 || $tags->isNotEmpty(),
                'detail' => ! $v2 ? 'A v1 key has no tag list: connect a v2 key.' : $tags->count().' tags'],
            ['key' => 'standard', 'label' => 'Tags match the standard', 'ok' => $v2 && $standard->isNotEmpty() && $missing->isEmpty(),
                'detail' => match (true) {
                    ! $v2 => 'Needs a v2 key.',
                    $standard->isEmpty() => 'No standard approved yet.',
                    $missing->isNotEmpty() => 'Missing: '.$missing->take(5)->implode(', ').($missing->count() > 5 ? ' and '.($missing->count() - 5).' more' : '').'. Settings › Workiz › Tags.',
                    default => 'Yes',
                }],
            ['key' => 'team', 'label' => 'Team read', 'ok' => WorkizTeamMember::query()->where('franchise_id', $branch)->exists(),
                'detail' => WorkizTeamMember::query()->where('franchise_id', $branch)->count().' people'],
            ['key' => 'web_login', 'label' => 'Web login reaches this branch (files, tag removal, payments report)',
                'ok' => $login !== null && WorkizWebSession::accountFor($login, $branch) !== null,
                'detail' => $login === null ? 'No Workiz web login is connected.' : (WorkizWebSession::accountFor($login, $branch) === null
                    ? 'Map this branch on the Workiz web login card.' : 'Yes')],
            ['key' => 'jobs', 'label' => 'Jobs mirrored', 'ok' => $jobs > 0,
                'detail' => $jobs > 0 ? $jobs.' jobs' : 'The first read is queued; it runs on the schedule.'],
        ];

        return $steps;
    }
}
