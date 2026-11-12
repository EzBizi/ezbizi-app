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
        $current = $releases[0] ?? null;
        if ($current !== null) {
            $current['eta_seconds'] = $this->eta($current, $this->medians($releases));
            $current['log'] = $this->log($current['sha']);
        }

        return [
            'current' => $current,
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

        // A run that never reported its end, overtaken by a later release that
        // finished, is not still pending: it was superseded (v0.0.0560 cb69d7d
        // sat on "No word for 3 hours" for a day).
        foreach ($releases as $i => $release) {
            if (! in_array($release['state'], ['running', 'stalled'], true)) {
                continue;
            }
            foreach (array_slice($releases, 0, $i) as $later) {
                if ($later['state'] === 'done') {
                    $releases[$i]['state'] = 'superseded';
                    break;
                }
            }
        }

        return $releases;
    }

    /** @return array<string, int> each stage's median duration, in seconds, over every release that finished it */
    private function medians(array $releases): array
    {
        $seen = [];
        foreach ($releases as $release) {
            foreach ($release['stages'] as $stage) {
                if ($stage['state'] === 'done' && $stage['seconds'] !== null) {
                    $seen[$stage['key']][] = $stage['seconds'];
                }
            }
        }

        $medians = [];
        foreach ($seen as $key => $values) {
            sort($values);
            $n = count($values);
            $medians[$key] = (int) ($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
        }

        return $medians;
    }

    /**
     * Seconds left: what the running stage has left (by its percent, else by
     * its time so far against its median) plus every stage still to come at
     * its median. Null when the release is not running or nothing is known.
     * Also stamps each stage's median_seconds.
     */
    private function eta(array &$release, array $medians): ?int
    {
        foreach ($release['stages'] as $i => $stage) {
            $release['stages'][$i]['median_seconds'] = $medians[$stage['key']] ?? null;
        }
        if ($release['state'] !== 'running') {
            return null;
        }

        $left = 0;
        $known = false;
        foreach ($release['stages'] as $stage) {
            $median = $medians[$stage['key']] ?? null;
            if ($median === null || $stage['state'] === 'done') {
                continue;
            }
            $known = true;
            if ($stage['state'] === 'running') {
                $left += $stage['percent'] > 0
                    ? (int) round($median * (100 - $stage['percent']) / 100)
                    : max(0, $median - ($stage['seconds'] ?? 0));
            } else {
                $left += $median;
            }
        }

        return $known ? $left : null;
    }

    /**
     * The last 40 lines of this commit's server gate log on the box (deploy.sh
     * writes {sha}-{time}-{pid}.log there). Anything that looks like a
     * credential is masked before it leaves.
     *
     * @return list<string>
     */
    private function log(string $sha): array
    {
        $dir = (string) config('ezbizi.gate_runs_dir');
        if ($dir === '' || ! preg_match('/^[0-9a-f]{7,40}$/', $sha)) {
            return [];
        }
        $files = glob(rtrim($dir, '/').'/'.$sha.'-*.log') ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        if ($files === [] || ! is_readable($files[0])) {
            return [];
        }

        $handle = @fopen($files[0], 'r');
        if ($handle === false) {
            return [];
        }
        fseek($handle, max(0, filesize($files[0]) - 8000));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $lines = array_slice(preg_split('/\r?\n/', rtrim($tail)) ?: [], -40);

        return array_values(array_map(fn (string $line) => (string) preg_replace(
            '/((?:token|secret|password|passwd|api[_-]?key|authorization)\w*["\']?\s*[:=]\s*["\']?)[^\s"\',]+/i',
            '$1[hidden]',
            mb_substr($line, 0, 300),
        ), $lines));
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

        // When each stage started (the line before its first one: the stage
        // before it ending, or the run starting) and when it finished.
        $startAt = [];
        $doneAt = [];
        $prevAt = null;
        foreach ($lines as $line) {
            if ($line['stage'] !== 'release') {
                $startAt[$line['stage']] ??= $prevAt ?? $line['at'];
                if (($line['state'] ?? null) === 'done') {
                    $doneAt[$line['stage']] = $line['at'];
                }
            }
            $prevAt = $line['at'];
        }

        $stages = [];
        $marked = false;
        foreach ($keys as $key) {
            $line = $latest[$key] ?? null;
            $state = $line['state'] ?? 'pending';
            $seconds = isset($startAt[$key]) && ($state === 'done' || $state === 'running')
                ? max(0, (int) CarbonImmutable::parse($startAt[$key])->diffInSeconds(
                    $state === 'done' ? CarbonImmutable::parse($doneAt[$key] ?? $line['at']) : now(), true))
                : null;
            $stages[] = [
                'key' => $key,
                'label' => self::STAGES[$key][0],
                'state' => $state,
                'percent' => $state === 'done' ? 100 : max(0, min(100, (int) ($line['percent'] ?? 0))),
                'message' => $line['message'] ?? null,
                'at' => $line['at'] ?? null,
                'seconds' => $seconds,
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
