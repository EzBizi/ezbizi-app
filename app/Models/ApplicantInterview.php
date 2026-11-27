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

/** One interview with an applicant: on the phone or in person (Shoval, 2026-11-20). */
class ApplicantInterview extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const KINDS = ['phone', 'in_person'];

    public const OUTCOMES = ['pass', 'fail', 'no_show'];

    protected $fillable = [
        'organization_id', 'applicant_id', 'kind', 'scheduled_at', 'held', 'outcome',
        'interviewer_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'held' => 'boolean'];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'Interviews with job applicants: when, who interviewed, how it went and notes.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('Deleted with the applicant it belongs to (cascade).');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('notes', PersonalDataEntry::CONTENT,
                'What the interviewer wrote about the applicant.',
                Erasure::keep('Removed with the applicant (cascade).')),
            new PersonalDataEntry('interviewer_user_id', PersonalDataEntry::IDENTITY,
                'The colleague who held the interview.',
                Erasure::keep('A foreign key to the colleague\'s account, anonymised at its one home.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('interviewer_user_id', $subject->getKey())
            : null;
    }
}
