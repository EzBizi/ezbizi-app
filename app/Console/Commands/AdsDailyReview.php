<?php

namespace App\Console\Commands;

use App\Models\AdsAccountState;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Rodrigo's 06:30 Daily Account Review, from what EzBizi already watches
 * (row D of docs/REPLACING-RODRIGO-BOT.md): per Local Services account, are
 * its ads running and are its licences / insurance clean.
 *
 * Monday: the whole snapshot. Other days: only what changed since the last
 * run, and nothing at all when nothing did. Raises `ads.daily_review` with a
 * ready {{summary}} for a WhatsApp or email step.
 *
 * Reads the hourly campaign watch and the 07:45 certification check; it
 * calls nobody. Business Profile standing joins when EzBizi's own GBP API
 * access does (not before 2026-10-24).
 */
class AdsDailyReview extends Command
{
    protected $signature = 'ezbizi:ads-daily-review';

    protected $description = 'Raise ads.daily_review: which Local Services accounts need attention';

    private const LIMIT = 4096;

    public function handle(TenantContext $tenant, SettingsResolver $settings, EventEmitter $events): int
    {
        $orgIds = AdsAccountState::query()->withoutGlobalScopes()->distinct()->pluck('organization_id');

        foreach (Organization::query()->withoutGlobalScopes()->whereIn('id', $orgIds)->get() as $organization) {
            $tenant->run($organization, function () use ($organization, $settings, $events) {
                $accounts = self::accounts();
                if ($accounts === []) {
                    return;
                }

                $key = 'ads:daily-review:'.$organization->getKey();
                $before = Cache::get($key);
                Cache::forever($key, array_map(fn ($a) => $a['signature'], $accounts));

                $now = now((string) $settings->get('timezone', $organization));
                $full = ! is_array($before) || $now->isMonday();
                $summary = $full
                    ? self::snapshot($organization->name, $now, $accounts)
                    : self::changes($organization->name, $now, $accounts, $before);

                if ($summary === null) {
                    return;
                }

                $events->emit('ads.daily_review', $organization, [
                    'summary' => mb_substr($summary, 0, self::LIMIT),
                    'accounts' => count($accounts),
                    'issues' => count(array_filter($accounts, fn ($a) => $a['issues'] !== [])),
                    'full' => $full,
                ]);
            });
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{issues: list<string>, paused: bool, signature: string}>
     */
    public static function accounts(): array
    {
        $names = SocialConnection::query()->pluck('display_name', 'id');
        $labels = SocialConnection::query()->pluck('label', 'id');
        $out = [];

        foreach (AdsAccountState::query()->get() as $state) {
            if (! $names->has($state->connection_id)) {
                continue;   // disconnected
            }

            $campaigns = (array) ($state->campaigns ?? []);
            $issues = [];
            $paused = false;

            if ($campaigns !== [] && ! collect($campaigns)->contains(fn ($c) => AdsCampaignWatch::showing((array) $c))) {
                $paused = collect($campaigns)->every(fn ($c) => ($c['status'] ?? '') === 'PAUSED');
                if (! $paused) {
                    $why = strtolower(str_replace('_', ' ', (string) ($campaigns[0]['primary_status'] ?? 'not eligible')));
                    $issues[] = "Ads not running ({$why})";
                }
            }

            foreach ((array) ($state->certification_problems ?? []) as $problem) {
                $issues[] = rtrim((string) ($problem['text'] ?? ''), '.');
            }

            $name = $labels->get($state->connection_id) ?: $names->get($state->connection_id);
            $out[$name] = [
                'issues' => $issues,
                'paused' => $paused,
                'signature' => ($paused ? 'paused|' : '').implode('|', $issues),
            ];
        }

        ksort($out);

        return $out;
    }

    /** @param array<string, array{issues: list<string>, paused: bool, signature: string}> $accounts */
    public static function snapshot(string $org, $now, array $accounts): string
    {
        $down = $verify = $paused = $healthy = [];

        foreach ($accounts as $name => $a) {
            if ($a['paused']) {
                $paused[] = $name;
            }
            $notRunning = array_values(array_filter($a['issues'], fn ($i) => str_starts_with($i, 'Ads not running')));
            $other = array_values(array_diff($a['issues'], $notRunning));
            if ($notRunning !== []) {
                $down[] = "*{$name}*: ".substr($notRunning[0], strlen('Ads not running '));
            }
            if ($other !== []) {
                $verify[] = "*{$name}*: ".implode('; ', $other);
            }
            if ($a['issues'] === [] && ! $a['paused']) {
                $healthy[] = $name;
            }
        }

        $issues = count(array_filter($accounts, fn ($a) => $a['issues'] !== []));
        $lines = ["{$org} | Account Review | ".$now->format('M j, g:i A T')." | {$issues} issues / ".count($accounts).' accounts'];

        if ($issues === 0) {
            $lines[] = 'No actionable issues. ✅';
        }
        foreach ([['*ADS NOT RUNNING* 🔴', $down], ['*LICENCES / INSURANCE* 🟡', $verify]] as [$title, $rows]) {
            if ($rows !== []) {
                $lines[] = '';
                $lines[] = $title.' ('.count($rows).'):';
                array_push($lines, ...$rows);
            }
        }
        if ($paused !== []) {
            $lines[] = '';
            $lines[] = '*PAUSED* ('.count($paused).'): '.implode(', ', $paused);
        }
        if ($healthy !== []) {
            $lines[] = '';
            $lines[] = '*HEALTHY* 🟢 ('.count($healthy).'): '.implode(', ', $healthy);
        }

        return implode("\n", $lines);
    }

    /**
     * Only what moved since the last run; null when nothing did.
     *
     * @param  array<string, array{issues: list<string>, paused: bool, signature: string}>  $accounts
     * @param  array<string, string>  $before
     */
    public static function changes(string $org, $now, array $accounts, array $before): ?string
    {
        $new = $resolved = $changed = [];

        foreach ($accounts as $name => $a) {
            $was = $before[$name] ?? '';
            if ($a['signature'] === $was) {
                continue;
            }
            $text = $a['paused'] && $a['issues'] === [] ? 'Paused' : implode('; ', $a['issues']);
            match (true) {
                $a['signature'] === '' => $resolved[] = "*{$name}*",
                $was === '' => $new[] = "*{$name}*: {$text}",
                default => $changed[] = "*{$name}*: {$text}",
            };
        }

        $count = count($new) + count($resolved) + count($changed);
        if ($count === 0) {
            return null;
        }

        $issues = count(array_filter($accounts, fn ($a) => $a['issues'] !== []));
        $lines = [
            "{$org} | Account Review | ".$now->format('M j, g:i A T')." | {$count} ".($count === 1 ? 'change' : 'changes'),
            "{$issues} active issues across ".count($accounts).' accounts',
        ];
        foreach ([['*NEW ISSUES* 🆕', $new], ['*CHANGED*', $changed], ['*RESOLVED* ✅', $resolved]] as [$title, $rows]) {
            if ($rows !== []) {
                $lines[] = '';
                $lines[] = $title.' ('.count($rows).'):';
                array_push($lines, ...$rows);
            }
        }

        return implode("\n", $lines);
    }
}
