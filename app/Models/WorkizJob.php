<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Workiz\WorkizJobAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * lives. The one exception is erasing a customer, which blanks the person on
 * their jobs and keeps them blank (keepErased).
 */
#[Fillable([
    'franchise_id', 'connection_id', 'remote_id', 'serial_id', 'client_id',
    'service_area', 'postal_code', 'city', 'state', 'country', 'address', 'unit',
    'latitude', 'longitude', 'status', 'sub_status', 'job_type', 'job_source', 'external_company_id', 'external_company_read_at',
    'booked_at', 'scheduled_for', 'scheduled_end', 'last_status_update', 'closed_at',
    'created_by', 'payload', 'synced_at', 'job_id', 'projected',
])]
class WorkizJob extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    /** What erasing a customer writes as the client's name on their jobs, and how a later read knows. */
    public const ERASED = 'Removed client';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'external_company_read_at' => 'datetime',
            'projected' => 'array',
            'booked_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'scheduled_end' => 'datetime',
            'last_status_update' => 'datetime',
            'closed_at' => 'datetime',
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

        $attributes = self::keepErased($connection, $attributes);

        // When it first reached Done or Canceled, kept while it stays closed: last_status_update
        // moves with every later edit (Reports audit, 2026-11-10). Reopened, it has none.
        $attributes['closed_at'] = in_array($attributes['status'], ['Done', 'Canceled'], true)
            ? (static::query()->where('franchise_id', $connection->scope_id)->where('remote_id', $attributes['remote_id'])->value('closed_at')
                ?? $attributes['last_status_update'])
            : null;

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
     *    kept; "done pending approval" is finished too. An OPEN unscheduled job
     *    does lose it: it is waiting for a date.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function keepWhatV2CannotSay(SocialConnection $connection, array $attributes): array
    {
        $held = static::query()->where('franchise_id', $connection->scope_id)->where('remote_id', $attributes['remote_id'])->first(['payload', 'created_by']);

        if ($held === null) {
            return $attributes;
        }

        // Who booked the job: v1 said (CreatedBy), v2 never does -- `createdBy`
        // is only a field v2 accepts on a write. Reports credits each booking
        // to a dispatcher by it, and the 2026-10-05 refresh blanked every row
        // before this kept it (FillWorkizCreators reads it back from v1).
        if (! array_key_exists('CreatedBy', $attributes['payload']) && array_key_exists('CreatedBy', (array) $held->payload)) {
            $attributes['payload']['CreatedBy'] = $held->payload['CreatedBy'];
        }

        if ($attributes['created_by'] === null) {
            unset($attributes['created_by']);
        }

        // Only a single read carries a job's files; a list read has no
        // `Attachments` at all (WorkizV2::job), and must not empty them.
        if (! array_key_exists('Attachments', $attributes['payload']) && isset($held->payload['Attachments'])) {
            $attributes['payload']['Attachments'] = $held->payload['Attachments'];
            $attributes['payload']['AttachmentsReadAt'] = $held->payload['AttachmentsReadAt'] ?? null;
        }

        unset($attributes['address'], $attributes['unit'], $attributes['city'], $attributes['state'],
            $attributes['postal_code'], $attributes['country'], $attributes['latitude'], $attributes['longitude']);

        foreach (['job_type', 'job_source', 'service_area'] as $named) {
            if ($attributes[$named] === null) {
                unset($attributes[$named]);
            }
        }

        if ($attributes['scheduled_for'] === null && in_array($attributes['status'], ['Done', 'Canceled', 'done pending approval'], true)) {
            unset($attributes['scheduled_for'], $attributes['scheduled_end']);
        }

        return $attributes;
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A mirror of the source system A-1 is migrating off, kept as long as the jobs and '
            .'customers projected from it are kept — deleting the mirror would strand those rows '
            .'with no provenance for where they came from. Erasing the customer a job was '
            .'projected onto blanks the person on it, and keeps them blank when Workiz, which '
            .'still holds them, answers for the job again.'
        );
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'Each Workiz job as the branch\'s Workiz answers for it, which the jobs and customers '
            .'EzBizi shows are projected from. Workiz has no separate client record, so it carries '
            .'the client\'s name, contact details and address on every job.';
    }

    /**
     * Keys inside `payload` are named `payload->Key`, which the Anonymiser
     * writes through Eloquent's JSON attributes.
     */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('payload->FirstName', PersonalDataEntry::IDENTITY,
                'Who the work is for, as Workiz recorded them. After an erasure it says so, '
                .'which is how a later read of the job knows to keep the person out.',
                Erasure::erase(self::ERASED)),
            new PersonalDataEntry('payload->LastName, payload->Company', PersonalDataEntry::IDENTITY,
                'Who the work is for, as Workiz recorded them.',
                Erasure::erase()),
            new PersonalDataEntry('payload->Email, payload->Phone, payload->PhoneExt, payload->SecondPhone, payload->SecondPhoneExt',
                PersonalDataEntry::CONTACT,
                'Arranging the work, and matching the job to a customer EzBizi already holds.',
                Erasure::erase()),
            new PersonalDataEntry('address, unit, city, state, postal_code, country, latitude, longitude, '
                .'payload->Address, payload->Unit, payload->City, payload->State, payload->PostalCode, '
                .'payload->Country, payload->Latitude, payload->Longitude',
                PersonalDataEntry::CONTACT,
                'Where the work happens, usually a home.',
                Erasure::erase()),
            new PersonalDataEntry('payload->JobNotes, payload->Notes, payload->Attachments', PersonalDataEntry::CONTENT,
                'The job\'s description, the notes staff leave on it and links to its photos, '
                .'which can say anything about the person and their home.',
                Erasure::erase()),
        ];
    }

    /**
     * The mirrored jobs of the customer's jobs.
     *
     * ponytail: the job link reaches every mirrored row (49,687 of 49,687 on
     * 2026-10-07); a row landed and not yet projected in the minute of a purge
     * is missed.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof Customer
            ? static::query()->whereIn('job_id', Job::withTrashed()->where('customer_id', $subject->getKey())->select('id'))
            : null;
    }

    /**
     * A job whose client was erased stays erased when Workiz answers for it
     * again: Workiz still holds the person, and every read would copy them
     * back. The job itself -- status, dates, money -- still follows Workiz.
     */
    private static function keepErased(SocialConnection $connection, array $attributes): array
    {
        $erased = static::query()->where('franchise_id', $connection->scope_id)
            ->where('remote_id', $attributes['remote_id'])->where('payload->FirstName', self::ERASED)->exists();

        if (! $erased) {
            return $attributes;
        }

        foreach (self::personalDataHeld() as $entry) {
            foreach ($entry->erasure->disposition === Erasure::ERASE ? $entry->columns() : [] as $column) {
                if (str_starts_with($column, 'payload->')) {
                    $attributes['payload'][substr($column, strlen('payload->'))] = $entry->erasure->standIn;
                } else {
                    $attributes[$column] = $entry->erasure->standIn;
                }
            }
        }

        return $attributes;
    }

    /**
     * The source Workiz's own reports credit the job to: its external company
     * when it was sent by one, else its job source (measured 2026-10-29 against
     * every branch's Sources report).
     */
    public function reportedSource(): ?string
    {
        if ($this->external_company_id !== null && $this->external_company_id !== '0') {
            $name = WorkizRecord::query()->where('franchise_id', $this->franchise_id)->where('type', 'external_company')
                ->where('remote_id', 'EC-'.$this->external_company_id)->first()?->payload['company_name'] ?? null;

            if (filled($name)) {
                return trim((string) $name);
            }
        }

        return filled($this->job_source) ? trim((string) $this->job_source) : null;
    }
}
