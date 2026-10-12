<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One call on a branch's Workiz phone, and Workiz's score for it. Written by SyncWorkizCalls. */
class WorkizCall extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'synced_at' => 'datetime',
            'pricing_discussed' => 'boolean',
            'booked' => 'boolean',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'called_at',
            'Kept a year, as RingCentral\'s calls are: a call is worth reading back for the season '
            .'it happened in, and it names the outside number on it.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'The calls on a branch\'s Workiz phone, so dispatchers\' calls and Workiz\'s scores for '
            .'them can be read beside EzBizi\'s own.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('from_number, to_number', PersonalDataEntry::CONTACT,
                'Who called whom: one side is the customer.',
                Erasure::erase()),
        ];
    }

    /**
     * The calls on the customer's jobs, or with their Workiz client.
     *
     * ponytail: a call re-read in the two days of overlap after a purge
     * copies the numbers back; a call older than that is never read again.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof Customer
            ? static::query()->where(fn (Builder $q) => $q
                ->whereIn('job_id', Job::withTrashed()->where('customer_id', $subject->getKey())->select('id'))
                ->orWhereIn('client_remote_id', WorkizClient::query()->where('customer_id', $subject->getKey())->whereNotNull('v2_id')->select('v2_id')))
            : null;
    }
}
