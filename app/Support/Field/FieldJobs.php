<?php

namespace App\Support\Field;

use App\Models\FieldGeocode;
use App\Models\Job;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * A tracked person's jobs for a day, and where each one is.
 *
 * "Their" job is `performed_by_user_id`, the person the job names as doing
 * the work. `scheduled_for` is franchise wall time, so a day is compared as
 * wall-clock text rather than converted.
 */
final class FieldJobs
{
    public function __construct(
        private readonly FieldTracking $tracking,
        private readonly FieldMaps $maps,
    ) {}

    /** Today's date where this person works. */
    public function today(User $user): string
    {
        return now()->timezone((string) $this->tracking->setting('timezone', $user))->toDateString();
    }

    /** @return Collection<int, Job> */
    public function on(User $user, string $date): Collection
    {
        return Job::query()
            ->with(['customer', 'franchise'])
            ->where('performed_by_user_id', $user->getKey())
            ->whereBetween('scheduled_for', ["{$date} 00:00:00", "{$date} 23:59:59"])
            ->orderBy('scheduled_for')
            ->get();
    }

    /**
     * Where a job is. With $ask, an address never seen before is geocoded now;
     * without it (the ingest path, which must stay fast) only a stored answer counts.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function pointFor(Job $job, bool $ask = false): ?array
    {
        return $this->pointAt($job->customer ? FieldGeocode::addressOf($job->customer) : null, $ask);
    }

    /**
     * Where an address is, from the stored answer, or from Google with $ask.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function pointAt(?string $address, bool $ask = false): ?array
    {
        if ($address === null) {
            return null;
        }

        $hash = FieldGeocode::hash($address);
        $stored = FieldGeocode::query()->where('address_hash', $hash)->first();

        if ($stored === null && $ask) {
            $answer = $this->maps->geocode($address);

            if ($answer === null) {
                return null; // could not ask; try again next time
            }

            $stored = FieldGeocode::query()->create([
                'address_hash' => $hash,
                'address' => $address,
                'latitude' => $answer ? $answer['lat'] : null,
                'longitude' => $answer ? $answer['lng'] : null,
                'status' => $answer ? FieldGeocode::OK : FieldGeocode::NOT_FOUND,
            ]);
        }

        return $stored?->status === FieldGeocode::OK
            ? ['lat' => $stored->latitude, 'lng' => $stored->longitude]
            : null;
    }
}
