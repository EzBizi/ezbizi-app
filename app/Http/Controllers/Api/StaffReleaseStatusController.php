<?php

namespace App\Http\Controllers\Api;

use Carbon\CarbonImmutable;

/**
 * GET /api/v1/staff/release-status — how far the release in flight has got.
 *
 * scripts/release.sh and scripts/deploy.sh append one JSON line per stage
 * boundary to `ezbizi.release_status_file` on this box (scripts/lib/
 * release-status.sh): {version, sha, stage, percent, state, message, at}, plus
 * `targets` on a run's first line. This folds those lines into releases.
 *
 * Staff-only (the `staff` middleware on the group): a release names commits and
 * failures, which no customer has any business reading.
 *
 * A release is one version + sha. A later run of the same commit (natives after
 * `--only web`, or a retry) continues the same release, so its targets are the
 * union of every run's and each stage shows its latest line.
 */
class StaffReleaseStatusController extends Controller
{
    /** Display order, and each stage's share of the overall bar. */
    private const STAGES = [
        'precheck' => ['Precheck', 10],
        'server_tests' => ['Server tests', 40],
        'web' => ['Deploy web', 15],
        'windows' => ['Windows', 10],
        'android' => ['Android', 10],
        'apple' => ['Apple', 15],
    ];

    /** A running release that has said nothing for this long has died unreported. */
    private const STALLED_AFTER_MINUTES = 180;

    public function index(): array
    {
        $releases = $this->releases($this->lines());

        return [
            'current' => $releases[0] ?? null,
            'recent' => array_slice($releases, 1, 5),
        ];
    }

    /**
     * The release on its way, for everybody signed in: the bar in What's been
     * built (Jose 11-12, "so people know when an update is coming"). Only the
     * version and how far along -- no stages, messages or SHAs, which are the
     * staff screen's. Null when nothing is running.
     */
    public function upcoming(): array
    {
        $current = $this->releases($this->lines())[0] ?? null;

        return ['data' => ($current['state'] ?? null) === 'running'
            ? ['version' => $current['version'], 'percent' => $current['percent']]
            : null];
    }

    /** @return list<array<string, mixed>> the file's last 2000 parsable lines, oldest first */
    private function lines(): array
    {
        $path = (string) config('ezbizi.release_status_file');
        if ($path === '' || ! is_readable($path)) {
            return [];
        }

        $raw = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = [];
        foreach (array_slice($raw, -2000) as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['sha'], $row['stage'], $row['at'])) {
                $lines[] = $row;
            }
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> newest first */
    private function releases(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $groups[($line['version'] ?? '').'|'.$line['sha']][] = $line;
        }

        $releases = array_map(fn (array $lines) => $this->release($lines), array_values($groups));
        usort($releases, fn ($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

        return $releases;
    }

    private function release(array $lines): array
    {
        $first = $lines[0];
        $last = $lines[array_key_last($lines)];

        $targets = [];
        $latest = [];      // stage => its latest line
        $run = null;       // the latest `release` line
        foreach ($lines as $line) {
            if (! empty($line['targets'])) {
                $targets = array_merge($targets, array_map('trim', explode(',', (string) $line['targets'])));
            }
            if ($line['stage'] === 'release') {
                $run = $line;
            } else {
                $latest[$line['stage']] = $line;
            }
        }

        // Which stages this release has: web brings the precheck and the
        // server gate with it; ios and macos are one Apple stage.
        $applies = static fn (string $stage) => match ($stage) {
            'precheck', 'server_tests', 'web' => in_array('web', $targets, true),
            'apple' => in_array('ios', $targets, true) || in_array('macos', $targets, true),
            default => in_array($stage, $targets, true),
        };
        $keys = $targets === []
            ? array_keys(self::STAGES)
            : array_values(array_filter(array_keys(self::STAGES), $applies));
        // A stage that reported without being a target still shows.
        foreach (array_keys(self::STAGES) as $key) {
            if (isset($latest[$key]) && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        $keys = array_values(array_intersect(array_keys(self::STAGES), $keys));

        $runState = $run['state'] ?? 'running';
        $finished = $run !== null && $runState !== 'running';
        $failed = $runState === 'failed';

        $stalled = ! $finished
            && CarbonImmutable::parse($last['at'])->lt(now()->subMinutes(self::STALLED_AFTER_MINUTES));

        $stages = [];
        $marked = false;
        foreach ($keys as $key) {
            $line = $latest[$key] ?? null;
            $state = $line['state'] ?? 'pending';
            $stages[] = [
                'key' => $key,
                'label' => self::STAGES[$key][0],
                'state' => $state,
                'percent' => $state === 'done' ? 100 : max(0, min(100, (int) ($line['percent'] ?? 0))),
                'message' => $line['message'] ?? null,
                'at' => $line['at'] ?? null,
            ];
            $marked = $marked || $state === 'failed';
        }

        // The run failed without its stage saying so (deploy.sh exits in a
        // dozen places): the first stage that did not finish is where it stopped.
        if ($failed && ! $marked) {
            foreach ($stages as $i => $stage) {
                if ($stage['state'] !== 'done') {
                    $stages[$i]['state'] = 'failed';
                    $stages[$i]['message'] = $run['message'] ?? $stage['message'];
                    break;
                }
            }
        }

        $weight = 0;
        $earned = 0.0;
        foreach ($stages as $stage) {
            $w = self::STAGES[$stage['key']][1];
            $weight += $w;
            $earned += $w * $stage['percent'] / 100;
        }
        $percent = $weight > 0 ? (int) floor($earned * 100 / $weight) : 0;
        if ($finished && ! $failed) {
            $percent = 100;
        }

        $startedAt = CarbonImmutable::parse($first['at']);
        $endedAt = $finished || $stalled ? CarbonImmutable::parse($last['at']) : now();

        return [
            'version' => $last['version'] ?? null,
            'sha' => $last['sha'],
            'targets' => array_values(array_unique($targets)),
            'state' => $failed ? 'failed' : ($finished ? 'done' : ($stalled ? 'stalled' : 'running')),
            'finished' => $finished,
            'failed' => $failed || $marked,
            'percent' => $percent,
            'stage' => $last['stage'],
            'message' => $last['message'] ?? null,
            'started_at' => $startedAt->toIso8601ZuluString(),
            'updated_at' => CarbonImmutable::parse($last['at'])->toIso8601ZuluString(),
            'elapsed_seconds' => max(0, (int) $startedAt->diffInSeconds($endedAt, true)),
            'stages' => $stages,
        ];
    }
}
