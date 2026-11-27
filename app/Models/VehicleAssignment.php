<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Database\Factories\VehicleAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who has the keys, and when they were handed over / taken back.
 *
 * Open = `released_at` null (at most one). No files — papers hang elsewhere.
 */
#[Fillable([
    'vehicle_id', 'user_id', 'assigned_at', 'released_at', 'note',
])]
class VehicleAssignment extends Model implements HoldsPersonalData
{
    /** @use HasFactory<VehicleAssignmentFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its vehicle's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['vehicles', Vehicle::class, 'vehicle_id'];


    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'Which employee held the keys for a vehicle, and for which stretch of time. '
            .'The assignment is the place the driver lives — the vehicle row does not store '
            .'a name of its own.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept for as long as the vehicle history matters. Hand-over dates answer who had '
            .'the keys when something happened, and an age-based window would delete the '
            .'oldest assignments first — the ones a later dispute still needs.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which employee this hand-over is about.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at '
                    .'its one home. Nulling it here would orphan a dated assignment from the '
                    .'person it is about without removing anything about them.')),
            new PersonalDataEntry('assigned_at, released_at, note', PersonalDataEntry::ACTIVITY,
                'When the keys moved, and any free-text note written at the time.',
                Erasure::keep('The dates are the history. Blanking them would leave a row that '
                    .'says somebody had the keys without saying when, which is worse than '
                    .'keeping dates against an already-anonymised account.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
