<?php

namespace App\Support\Web\Hours;

use App\Models\WebSite;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Review, then apply, one week edit across several websites and destinations.
 *
 * ── Review is a real read, and apply re-reads ──
 *
 * `review` reads every destination's live week and plans against it. What it
 * planned travels back as an encrypted token: which sites, which destinations,
 * the fingerprint of what each held, and the week it will become. `apply`
 * takes only that token, so the week written is exactly the week reviewed —
 * nothing in the second request can change it.
 *
 * Per place, apply reads again and decides:
 *  - holds the reviewed final week already → Unchanged (a repeat is idempotent);
 *  - holds what was reviewed → written, re-read, Changed or Failed;
 *  - holds anything else → Stale: somebody changed it, review again.
 *
 * One place failing never touches another: each is read, written and
 * verified on its own.
 */
final class HoursBatch
{
    /** How long a review stays applicable. A review older than this is a guess. */
    private const TTL = 1800;

    /** @param  array<string, HoursDestination>  $destinations */
    public function __construct(private readonly array $destinations) {}

    public static function make(): self
    {
        return new self([
            WebsiteHours::KEY => new WebsiteHours,
            BusinessProfileHours::KEY => new BusinessProfileHours,
        ]);
    }

    /** @return array<string, HoursDestination> */
    public function destinations(): array
    {
        return $this->destinations;
    }

    /**
     * @param  iterable<WebSite>  $sites
     * @param  list<string>  $destinations
     * @param  array<int, array{action: string, open: ?string, close: ?string}>  $days
     * @return array{token: string, places: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function review(iterable $sites, array $destinations, array $days, ?string $sourceZone, ?string $notBefore, string $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable;
        $places = [];
        $plan = [];

        foreach ($sites as $site) {
            $zone = (new WebsiteHours)->effective($site, 'timezone');

            foreach ($destinations as $key) {
                $destination = $this->destinations[$key];
                $place = ['site_id' => $site->getKey(), 'host' => $site->host, 'destination' => $key,
                    'destination_label' => $destination->label(), 'timezone' => $zone ?: null];

                if (($why = $destination->unavailable($site)) !== null) {
                    $places[] = $place + ['status' => 'unavailable', 'reasons' => [$why], 'days' => []];

                    continue;
                }

                if ($zone === '' && $sourceZone !== null) {
                    $places[] = $place + ['status' => 'blocked', 'reasons' => ['This site has no time zone, so hours cannot be converted for it.'], 'days' => []];

                    continue;
                }

                $live = $destination->read($site);
                $result = WeekEdit::plan($live['week'], $days, $zone ?: 'UTC', $sourceZone, $notBefore, $now);
                $places[] = $place + ['status' => $result['status'], 'reasons' => $result['reasons'], 'days' => $result['days']];

                if ($result['status'] === 'change') {
                    $plan[] = ['site' => $site->getKey(), 'destination' => $key, 'from' => $live['fingerprint'], 'week' => $result['week']];
                }
            }
        }

        $token = Crypt::encryptString(json_encode([
            'user' => $userId,
            'expires' => $now->getTimestamp() + self::TTL,
            'plan' => $plan,
        ]));

        return ['token' => $token, 'places' => $places, 'summary' => self::summary($places)];
    }

    /**
     * @param  callable(string): ?WebSite  $findSite  the site by id, or null when this user may not edit it
     * @return array{results: list<array<string, mixed>>, summary: array<string, int>}|null  null when the token is not
     *                                                                                        a current review of this user's
     */
    public function apply(string $token, string $userId, callable $findSite, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable;

        try {
            $review = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($review) || ($review['user'] ?? null) !== $userId || ($review['expires'] ?? 0) < $now->getTimestamp()) {
            return null;
        }

        $results = [];

        foreach ($review['plan'] as $item) {
            $site = $findSite((string) $item['site']);
            $destination = $this->destinations[$item['destination']] ?? null;
            $result = ['site_id' => $item['site'], 'host' => $site?->host, 'destination' => $item['destination']];

            if ($site === null || $destination === null) {
                $results[] = $result + ['status' => 'failed', 'reason' => 'This website is no longer yours to edit.'];

                continue;
            }

            try {
                $live = $destination->read($site);

                if ($live['week'] !== null && $live['week'] == $item['week']) {
                    $results[] = $result + ['status' => 'unchanged', 'reason' => null];

                    continue;
                }

                if ($live['fingerprint'] !== $item['from']) {
                    $results[] = $result + ['status' => 'stale', 'reason' => 'Changed since the review. Review again.'];

                    continue;
                }

                $destination->write($site, $item['week']);
                $after = $destination->read($site)['week'];
                $results[] = $result + ($after == $item['week']
                    ? ['status' => 'changed', 'reason' => null]
                    : ['status' => 'failed', 'reason' => 'Written, but reading it back did not show the new hours.']);
            } catch (Throwable $e) {
                // A timeout is not a success: the destination may or may not hold it.
                report($e);
                $results[] = $result + ['status' => 'unconfirmed', 'reason' => 'No clear answer from '.$destination->label().'. Review again to see what it holds.'];
            }
        }

        return ['results' => $results, 'summary' => self::summary($results)];
    }

    /** @param  list<array{status: string}>  $rows */
    private static function summary(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        return $counts;
    }
}
