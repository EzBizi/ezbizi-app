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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody who applied for a position (Shoval, 2026-11-20: the Recruiting
 * report). Position, source and stage are values from the tenant's lists
 * (hr.recruiting.positions / sources / stages). A job-applicant lead can be
 * linked; a hire links the account they became.
 */
class Applicant extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'franchise_id', 'lead_id', 'name', 'phone', 'email', 'position', 'source',
        'stage', 'relevant', 'applied_at', 'first_response_at', 'stage_changed_at', 'hired_user_id',
        'notes', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'relevant' => 'boolean',
            'applied_at' => 'datetime',
            'first_response_at' => 'datetime',
            'stage_changed_at' => 'datetime',
        ];
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(ApplicantInterview::class)->orderBy('scheduled_at');
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'People who applied for a job with this organization: their contact details, the '
            .'position, how far they got and HR\'s notes, so the organization can run its hiring.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept as the organization\'s hiring record until HR deletes the applicant or the '
            .'organization is deleted.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('name, phone, email', PersonalDataEntry::CONTACT,
                'What the applicant gave so the organization could reach them about the job.',
                Erasure::keep('The applicant is nobody on this platform, so there is no account to erase '
                    .'them through; HR deletes the applicant row itself.')),
            new PersonalDataEntry('notes', PersonalDataEntry::CONTENT,
                'What HR wrote about the applicant.',
                Erasure::keep('Removed with the applicant row when HR deletes it.')),
            new PersonalDataEntry('created_by_user_id', PersonalDataEntry::IDENTITY,
                'The colleague who added the applicant.',
                Erasure::keep('A foreign key to the colleague\'s account, anonymised at its one home.')),
            new PersonalDataEntry('hired_user_id', PersonalDataEntry::IDENTITY,
                'The account the applicant became when hired.',
                Erasure::keep('A foreign key to that account, anonymised at its one home.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where(fn ($q) => $q->where('created_by_user_id', $subject->getKey())
                ->orWhere('hired_user_id', $subject->getKey()))
            : null;
    }
}
