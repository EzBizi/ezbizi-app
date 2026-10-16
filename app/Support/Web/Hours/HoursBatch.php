<?php

namespace App\Support\Web\Hours;

use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Review, then apply, one week edit across several places and destinations.
 *
 * A place is what a destination writes: a website for its own hours, a
 * Business Profile location for Google's.
 *
 * ── Review is a real read, and apply re-reads ──
 *
 * `review` reads every place's live week and plans against it. What it
 * planned travels back as an encrypted token: which places, the fingerprint
 * of what each held, and the week it will become. `apply` takes only that
 * token, so the week written is exactly the week reviewed.
 *
 * Per place, apply reads again and reports one of:
 *  - Unchanged: it holds the reviewed week already (a repeat is idempotent);
 *  - Changed: written, read back, and it holds the new week;
 *  - Failed: written, but reading it back did not show the new week;
 *  - Blocked: nothing was written (the destination said no, or could not be read first);
 *  - Unconfirmed: no clear answer to the write, so it may or may not hold it;
 *  - Stale: it changed since the review; review again.
 *
 * One place failing never touches another.
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
     * @param  list<array{0: string, 1: Model}>  $targets  [destination key, place]
     * @param  array<int, array{action: string, open: ?string, close: ?string}>  $days
     * @return array{token: string, places: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function review(array $targets, array $days, ?string $sourceZone, ?string $notBefore, string $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable;
        $places = [];
        $plan = [];

        foreach ($targets as [$key, $model]) {
            $destination = $this->destinations[$key];
            $zone = $destination->zone($model);
            $place = self::place($destination, $model) + ['timezone' => $zone ?: null];

            if (($why = $destination->unavailable($model)) !== null) {
                $places[] = $place + ['status' => 'unavailable', 'reasons' => [$why], 'days' => []];

                continue;
            }

            if ($zone === '' && $sourceZone !== null) {
                $places[] = $place + ['status' => 'blocked', 'reasons' => ['This place has no time zone, so hours cannot be converted for it.'], 'days' => []];

                continue;
            }

            try {
                $live = $destination->read($model);
            } catch (Throwable $e) {
                report($e);
                $places[] = $place + ['status' => 'blocked', 'reasons' => ['Could not read its hours from '.$destination->label().'.'], 'days' => []];

                continue;
            }

            $result = WeekEdit::plan($live['week'], $days, $zone ?: 'UTC', $sourceZone, $notBefore, $now);
            $places[] = $place + ['status' => $result['status'], 'reasons' => $result['reasons'], 'days' => $result['days']];

            if ($result['status'] === 'change') {
                $plan[] = ['id' => $model->getKey(), 'destination' => $key, 'from' => $live['fingerprint'], 'week' => $result['week']];
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
     * @param  callable(string $destination, string $id): ?Model  $findPlace  the place, or null when this user may not edit it
     * @return array{results: list<array<string, mixed>>, summary: array<string, int>}|null null when the token is not a current review of this user's
     */
    public function apply(string $token, string $userId, callable $findPlace, ?DateTimeImmutable $now = null): ?array
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
            $destination = $this->destinations[$item['destination']] ?? null;
            $model = $destination === null ? null : $findPlace($item['destination'], (string) $item['id']);
            $result = $model === null
                ? ['id' => $item['id'], 'name' => null, 'destination' => $item['destination']]
                : self::place($destination, $model);

            if ($model === null) {
                $results[] = $result + ['status' => 'blocked', 'reason' => 'This place is no longer yours to edit.', 'week' => null];

                continue;
            }

            $results[] = $result + $this->applyOne($destination, $model, $item) + ['week' => $item['week']];
        }

        return ['results' => $results, 'summary' => self::summary($results)];
    }

    /** @return array{status: string, reason: ?string} */
    private function applyOne(HoursDestination $destination, Model $model, array $item): array
    {
        try {
            $live = $destination->read($model);
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'blocked', 'reason' => 'Could not read its hours from '.$destination->label().' first, so nothing was written.'];
        }

        if ($live['week'] !== null && $live['week'] == $item['week']) {
            return ['status' => 'unchanged', 'reason' => null];
        }

        if ($live['fingerprint'] !== $item['from']) {
            return ['status' => 'stale', 'reason' => 'Changed since the review. Review again.'];
        }

        try {
            $destination->write($model, $item['week']);
        } catch (HoursRefused $e) {
            return ['status' => 'blocked', 'reason' => $e->getMessage()];
        } catch (Throwable $e) {
            // A timeout is not a success: the destination may or may not hold it.
            report($e);

            return ['status' => 'unconfirmed', 'reason' => 'No clear answer from '.$destination->label().'. Review again to see what it holds.'];
        }

        try {
            $after = $destination->read($model)['week'];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'unconfirmed', 'reason' => 'Written, but '.$destination->label().' could not be read back to confirm it.'];
        }

        return $after == $item['week']
            ? ['status' => 'changed', 'reason' => null]
            : ['status' => 'failed', 'reason' => 'Written, but reading it back did not show the new hours.'];
    }

    /** @return array{id: string, name: string, destination: string, destination_label: string, site_id?: string, host?: string, location_id?: string} */
    private static function place(HoursDestination $destination, Model $model): array
    {
        $id = (string) $model->getKey();
        // site_id/host stay for the website editor already reading them.
        $legacy = $destination->key() === WebsiteHours::KEY
            ? ['site_id' => $id, 'host' => $destination->placeName($model)]
            : ['location_id' => $id];

        return ['id' => $id, 'name' => $destination->placeName($model), 'destination' => $destination->key(),
            'destination_label' => $destination->label()] + $legacy;
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
