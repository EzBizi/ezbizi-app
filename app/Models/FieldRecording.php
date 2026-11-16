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

/** A safety recording a technician made on a visit, in one-minute chunks. */
class FieldRecording extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A recording made, transcribed or flagged is its technician's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['field', User::class, 'user_id'];

    use HasUuids;

    /** Ninety days, unless a manager flagged it (an incident, a training example). */
    public const KEEP_DAYS = 90;

    /** The phone stops by itself after this long. */
    public const MAX_HOURS = 4;

    public const STOP_REASONS = ['done', 'customer_request', 'personal', 'left_job', 'max_duration', 'recovered'];

    protected $fillable = [
        'organization_id', 'user_id', 'job_id', 'started_at', 'ended_at', 'stop_reason',
        'duration_s', 'chunk_count', 'total_bytes', 'events', 'customer_told', 'summary', 'summarized_at', 'flagged', 'flag_reason', 'flagged_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'events' => 'array',
            'flagged' => 'bool',
            'customer_told' => 'bool',
            'summary' => 'array',
            'summarized_at' => 'datetime',
        ];
    }

    public static function personalDataPurpose(): string
    {
        return 'Audio a technician chose to record on a visit, for their safety and for training.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::elsewhere('ezbizi:field-sweep',
            'Ninety days, unless a manager flagged it as an incident or a training example; '
            .'the sweep deletes the audio files with the rows.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id, job_id, started_at, ended_at, events', PersonalDataEntry::ACTIVITY,
                'Who recorded, on which visit, and when.',
                Erasure::drop('The recording is the person\'s voice and their visit; the row and its audio go.')),
            new PersonalDataEntry('flagged_by', PersonalDataEntry::IDENTITY,
                'Which manager kept the recording past ninety days.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at its one home.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(FieldRecordingSegment::class, 'recording_id')->orderBy('started_at');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(FieldRecordingChunk::class, 'recording_id')->orderBy('seq');
    }
}
