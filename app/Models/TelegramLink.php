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

/** A staff member's Telegram account, linked by sharing their phone with the bot. */
class TelegramLink extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'telegram_links';

    protected $fillable = ['user_id', 'telegram_user_id', 'chat_id', 'linked_at'];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'telegram_user_id' => 'integer', 'chat_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'Which Telegram account belongs to which staff member, so the Tech Assistant bot answers them.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('Kept while the staff account exists; /unlink in Telegram removes it at once.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'The staff member this Telegram account belongs to.', Erasure::drop('Only the link; they can share their phone again.')),
            new PersonalDataEntry('telegram_user_id,chat_id', PersonalDataEntry::TECHNICAL,
                'Telegram ids the bot replies to.', Erasure::drop('Only the link; they can share their phone again.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
    }
}
