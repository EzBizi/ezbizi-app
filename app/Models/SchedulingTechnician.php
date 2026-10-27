<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A technician on EzBizi's own roster: who can be put on a job, and the
 * service areas they cover. A person, with or without an EzBizi login.
 */
#[Fillable(['organization_id', 'franchise_id', 'user_id', 'name', 'email', 'phone', 'is_field_tech', 'is_active', 'workiz_member_id'])]
class SchedulingTechnician extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['is_field_tech' => 'boolean', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(ServiceArea::class, 'scheduling_technician_areas', 'technician_id', 'service_area_id');
    }

    public static function personalDataPurpose(): string
    {
        return 'The technicians a branch can put on a job, so that work is booked and shown by who does it. Staff of the organization, not customers.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Kept as long as the jobs it names; a leaver is made inactive, not deleted, so past work still says who did it.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('name', PersonalDataEntry::IDENTITY, 'Who does the work, as the board and the job show it.', Erasure::erase('Removed technician')),
            new PersonalDataEntry('email', PersonalDataEntry::CONTACT, 'How the office reaches them and how a login is linked.', Erasure::erase(null)),
            new PersonalDataEntry('phone', PersonalDataEntry::CONTACT, 'How the office reaches them in the field.', Erasure::erase(null)),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        if (! $subject instanceof User) {
            return null;
        }
        $email = mb_strtolower(trim((string) $subject->email));

        return static::query()->where(fn (Builder $q) => $q->where('user_id', $subject->getKey())
            ->when($email !== '', fn ($q) => $q->orWhereRaw('LOWER(email) = ?', [$email])));
    }
}
