<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Workiz\WorkizJobAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Workiz job, as Workiz reported it.
 *
 * A MIRROR. Nothing in EzBizi edits these rows: the sweep overwrites them on
 * every run, so a local edit here would be reverted with nothing logged. What
 * EzBizi decides about a job belongs on the projected `jobs` row, not here —
 * the same split `leads` makes between `source_status` (the source's word,
 * overwritten every sync) and `status` (ours, never touched by a sweep).
 *
 * That split is why two-way sync does not change this rule. An edit made in
 * EzBizi is pushed to WORKIZ and arrives back here on the next sweep, so this
 * table still only ever holds Workiz's answer. It is never the place an edit
 * lives.
 */
#[Fillable([
    'franchise_id', 'connection_id', 'remote_id', 'serial_id', 'client_id',
    'service_area', 'postal_code', 'city', 'state', 'country', 'address', 'unit',
    'latitude', 'longitude', 'status', 'sub_status', 'job_type', 'job_source',
    'booked_at', 'scheduled_for', 'scheduled_end', 'last_status_update',
    'created_by', 'payload', 'synced_at', 'job_id', 'projected',
])]
class WorkizJob extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'projected' => 'array',
            'booked_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'scheduled_end' => 'datetime',
            'last_status_update' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'connection_id');
    }

    /**
     * Write one Workiz payload into the mirror. The sweep, the re-read and the
     * writer's read-back all come through here, so there is one key and one
     * set of rules.
     */
    public static function land(SocialConnection $connection, array $payload): bool
    {
        $attributes = WorkizJobAttributes::fromPayload($payload);

        // A row with no id cannot be upserted idempotently; keying on '' would
        // collapse every such job onto one row.
        if ($attributes['remote_id'] === '') {
            return false;
        }

        if (isset($payload['v2'])) {
            $attributes = self::keepWhatV2CannotSay($connection, $attributes);
        }

        static::query()->updateOrCreate(
            [
                // The FRANCHISE, not the connection. Disconnect deletes the
                // connection row and reconnect mints a new id, so a
                // connection-keyed upsert would land every job a second time.
                // One branch holds one Workiz account, so the franchise is the
                // stable name for the book a job came from.
                'franchise_id' => $connection->scope_id,
                'remote_id' => $attributes['remote_id'],
            ],
            $attributes + [
                // Recorded, but never part of the identity. Placement comes
                // from the CONNECTION and never from the payload: Workiz cannot
                // know which EzBizi franchise a job belongs to, and a payload
                // that claimed to would be a caller-supplied tenant id.
                'connection_id' => $connection->getKey(),
                'synced_at' => now(),
            ],
        );

        return true;
    }

    /**
     * A v2 job landing on a row EzBizi already holds, measured against v1 on
     * all ten accounts (2026-10-05) and read live on Connecticut:
     *
     *  - v2 has no address of the job's own. Its address is the CLIENT's (job
     *    0T933A: v1 Bolton CT, the house; v2 Avon IN, the client), so a row
     *    keeps the place v1 gave it.
     *  - v2 cannot name a job type, source or area that is gone from the
     *    account (null where v1 still named one): kept.
     *  - A finished job Workiz marks unscheduled has no date in v2 (009OCC:
     *    Done, v1 2026-07-16 08:00, v2 null). Its visit date is history and is
     *    kept. An OPEN unscheduled job does lose it: it is waiting for a date.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function keepWhatV2CannotSay(SocialConnection $connection, array $attributes): array
    {
        $held = static::query()->where('franchise_id', $connection->scope_id)->where('remote_id', $attributes['remote_id'])->exists();

        if (! $held) {
            return $attributes;
        }

        unset($attributes['address'], $attributes['unit'], $attributes['city'], $attributes['state'],
            $attributes['postal_code'], $attributes['country'], $attributes['latitude'], $attributes['longitude']);

        foreach (['job_type', 'job_source', 'service_area'] as $named) {
            if ($attributes[$named] === null) {
                unset($attributes[$named]);
            }
        }

        if ($attributes['scheduled_for'] === null && in_array($attributes['status'], ['Done', 'Canceled'], true)) {
            unset($attributes['scheduled_for'], $attributes['scheduled_end']);
        }

        return $attributes;
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A mirror of the source system A-1 is migrating off, kept as long as the jobs and '
            .'customers projected from it are kept — deleting the mirror would strand those rows '
            .'with no provenance for where they came from. The personal data in it (the '
            .'customer\'s name, contact details and service address, which Workiz carries on '
            .'every job because it has no separate client record) is erased by erasing the '
            .'customer it was projected onto: this table holds no person EzBizi does not also '
            .'hold in `customers`, and `customers` decides about itself.'
        );
    }
}
