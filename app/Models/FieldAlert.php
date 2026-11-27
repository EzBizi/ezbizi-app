<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A safety alert on the Field dashboard: SOS, stopped too long, or lost signal. */
class FieldAlert extends Model implements CarriesAutomationScope, HoldsPersonalData
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** An alert raised or acknowledged is its technician's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['field', User::class, 'user_id'];

    use HasUuids;

    public const SOS = 'sos';

    public const STOPPED = 'stopped';

    public const SIGNAL_LOST = 'signal_lost';

    protected $fillable = [
        'organization_id', 'user_id', 'kind', 'job_id', 'latitude', 'longitude',
        'acknowledged_at', 'acknowledged_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'acknowledged_at' => 'datetime',
        ];
    }

    public static function personalDataPurpose(): string
    {
        return 'A safety alert about a tracked employee, and who acknowledged it.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'created_at',
            'The same ninety days as the trail it was raised from; the audit log keeps '
            .'who acknowledged an SOS on its own terms.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id, latitude, longitude', PersonalDataEntry::ACTIVITY,
                'Who the alert is about and where they were.',
                Erasure::drop('The alert is only about the person; the row goes.')),
            new PersonalDataEntry('acknowledged_by', PersonalDataEntry::IDENTITY,
                'Which manager acknowledged the alert, so a later review can ask them what they did.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at its one home.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    /**
     * Where the PERSON sits (their first assignment, the place their Field
     * settings resolve at). Without this a "notify" rule on SOS would tell
     * every active user in the organization.
     */
    public function automationScope(): array
    {
        $assignment = $this->user?->assignments()->orderBy('created_at')->first();

        return match ($assignment?->scopeType()) {
            Assignment::SCOPE_FRANCHISE => ['franchise', $assignment->assigned_franchise_id],
            Assignment::SCOPE_COMPANY => ['company', $assignment->assigned_company_id],
            default => [null, null],
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}
