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
 * One alert a person sent to chosen colleagues (Communication ▸ Alerts).
 *
 * Jose, 2026-10-07: "send a notificiations to specific users, like an alert
 * system ... a pop up on the screen, that they can dismiss". Each recipient's
 * copy is a Notification with source `alert` and this as its subject; a
 * recipient dismissing it is that row's read_at.
 */
class Alert extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;   // an alert is never edited after it is sent

    protected $fillable = ['organization_id', 'sender_id', 'kind', 'message', 'context', 'choices', 'allow_text'];

    public const KIND_QUESTION = 'question';

    protected $casts = ['choices' => 'array', 'allow_text' => 'boolean'];

    /** Answers, one per person asked (questions only). */
    public function replies(): HasMany
    {
        return $this->hasMany(AlertReply::class);
    }

    public function isQuestion(): bool
    {
        return $this->kind === self::KIND_QUESTION;
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** Each recipient's copy; its read_at is when they dismissed it. */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Notification::class, 'subject_id')
            ->where('subject_type', self::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'Alerts somebody sent to chosen colleagues, kept so the sender can see who has '
            .'dismissed each one.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(6, 'created_at',
            'The same window as the notifications it was delivered as: once those are gone '
            .'there is nobody left to have dismissed it, and "dismissed by 0 of 0" tells the '
            .'sender nothing.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('sender_id', PersonalDataEntry::ACTIVITY,
                'Who sent the alert, so they can see which colleagues have dismissed it.',
                Erasure::drop('The alert is the sender\'s act; with the sender erased there '
                    .'is nobody whose sent list it belongs to, and the column is NOT NULL.')),
            new PersonalDataEntry('context', PersonalDataEntry::CONTENT,
                'The background a question carries. Free text, so it can name a third party.',
                Erasure::drop('Free text with no schema; it goes with the row.')),
            new PersonalDataEntry('message', PersonalDataEntry::CONTENT,
                'What the alert said. Free text, so it can name a third party.',
                Erasure::drop('Free text with no schema: nothing can tell which words are a '
                    .'name. It goes with the row above.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('sender_id', $subject->getKey())
            : null;
    }
}
