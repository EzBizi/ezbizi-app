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

        return self::check($connection, fix: true);
    }

    /** The watchdog's last verdict: "Workiz sync: OK · last checked 2 min ago". */
    private static function sync(SocialConnection $connection): array
    {
        $state = \Illuminate\Support\Facades\Cache::get(\App\Console\Commands\WorkizWatchdog::STATE.$connection->getKey());
        $at = isset($state['checked_at']) ? \Illuminate\Support\Carbon::parse($state['checked_at']) : null;
        $fresh = $at !== null && $at->gt(now()->subMinutes(15));

        return ['key' => 'sync', 'label' => 'Workiz sync', 'ok' => $fresh && ($state['items'] ?? []) === [],
            'detail' => match (true) {
                $at === null => 'Not checked yet.',
                ! $fresh => 'The watchdog has not run since '.$at->diffForHumans().'.',
                ($state['items'] ?? []) !== [] => implode(' ', $state['items']).' Checked '.$at->diffForHumans().'.',
                default => 'OK · last checked '.$at->diffForHumans().'.',
            }];
    }

    /** When the webhook last nudged this account (WorkizWebhookController). */
    public const NUDGED = 'workiz.nudged.';

    /**
     * The web login sits behind a CAPTCHA, so nothing re-checks it unattended:
     * its status and age are shown, and whether it reaches this branch is asked
     * of Workiz only at Connect (a live read), then kept.
     */
    private static function webLogin(SocialConnection $connection, ?SocialConnection $login, bool $live): array
    {
        $step = ['key' => 'web_login', 'label' => 'Web login reaches this branch (files, tag removal, payments report)'];

        if ($login === null) {
            return $step + ['ok' => false, 'detail' => 'No Workiz web login is connected.'];
        }

        $key = 'workiz.web_reach.'.$connection->getKey();

        if ($live) {
            \Illuminate\Support\Facades\Cache::forever($key, WorkizWebSession::accountFor($login, $connection->scope_id) !== null);
        }

        $reaches = \Illuminate\Support\Facades\Cache::get($key);
        $age = 'last used '.($login->last_reached_at ?? $login->updated_at)?->diffForHumans();

        return $step + ['ok' => $login->status === 'connected' && $reaches !== false,
            'detail' => match (true) {
                $login->status !== 'connected' => 'The web login is '.$login->status.' ('.$age.'): sign in again on its card.',
                $reaches === false => 'Map this branch on the Workiz web login card ('.$age.').',
                default => 'Yes, '.$age.'.',
            }];
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
        // Mirrored but not yet an EzBizi job; a normal pass takes about 2 minutes.
        $waiting = WorkizJob::query()->where('franchise_id', $branch)->whereNull('job_id')->min('created_at');
        $nudged = ($at = \Illuminate\Support\Facades\Cache::get(self::NUDGED.$connection->getKey())) ? \Illuminate\Support\Carbon::parse($at) : null;

        $steps = [
            ['key' => 'key', 'label' => 'API key works', 'ok' => $connection->status === 'connected',
                'detail' => $connection->status === 'connected' ? ($v2 ? 'v2 key' : 'v1 key') : (string) $connection->status_reason],
            ['key' => 'writes', 'label' => 'EzBizi can write to Workiz', 'ok' => $v2 || filled($credentials['auth_secret'] ?? null),
                'detail' => $v2 || filled($credentials['auth_secret'] ?? null) ? 'Yes' : 'Writes off — add the v1 secret (or connect a v2 key).'],
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
            self::webLogin($connection, $login, $fix),
            ['key' => 'webhook', 'label' => 'Workiz tells EzBizi of changes (webhook)', 'ok' => $nudged !== null && now()->diffInDays($nudged) < 7,
                'detail' => $nudged !== null ? 'Last change received '.$nudged->diffForHumans().'.'
                    : 'Workiz has no API for this: in Workiz › Automation Center add a rule "post webhook" to '.url('/api/v1/social/workiz/webhook/'.$connection->getKey()).'.'],
            ['key' => 'jobs', 'label' => 'Jobs mirrored', 'ok' => $jobs > 0,
                'detail' => $jobs > 0 ? $jobs.' jobs' : 'The first read is queued; it runs on the schedule.'],
            self::sync($connection),
            ['key' => 'projection', 'label' => 'New Workiz jobs reach EzBizi within minutes',
                'ok' => $waiting === null || \Illuminate\Support\Carbon::parse($waiting)->gt(now()->subMinutes(10)),
                'detail' => $waiting === null ? 'Yes' : 'Oldest job waiting since '.\Illuminate\Support\Carbon::parse($waiting)->diffForHumans().'.'],
        ];

        return $steps;
    }
}
