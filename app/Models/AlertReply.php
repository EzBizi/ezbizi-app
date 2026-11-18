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

/** One person's answer to a question (an Alert of kind `question`), Jose 2026-11-18. */
class AlertReply extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;   // an answer is given once

    protected $fillable = ['organization_id', 'alert_id', 'user_id', 'choice', 'text'];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'Answers people gave to questions a colleague asked them, kept so the asker can read them.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'An answer means nothing without the question it answers, so it goes when the question does.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::ACTIVITY,
                'Who answered, so the asker knows whose answer it is.',
                Erasure::drop('The answer is that person\'s act; the column is NOT NULL.')),
            new PersonalDataEntry('text', PersonalDataEntry::CONTENT,
                'What they wrote. Free text, so it can name a third party.',
                Erasure::drop('Free text with no schema; it goes with the row.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }
}
