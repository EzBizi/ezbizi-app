<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in a Chat conversation. Never leaves the organization. */
class ChatMessage extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasAttachments;
    use HasUuids;

    public const KINDS = ['text', 'image', 'video', 'audio', 'voice', 'file', 'call', 'system'];

    protected $fillable = [
        'organization_id', 'chat_id', 'sender_id', 'kind', 'body', 'reply_to_id',
        'forwarded', 'meta', 'reactions', 'starred_by', 'hidden_for', 'edited_at', 'deleted_at',
    ];

    // Microseconds: receipts compare created_at with a member's read_at, and
    // two messages in one second must still order.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'forwarded' => 'boolean',
            'meta' => 'array',
            'reactions' => 'array',
            'starred_by' => 'array',
            'hidden_for' => 'array',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** Two years, as staff_messages: a working conversation, not a business record. */
    public static function retention(): RetentionPolicy
    {
        // ponytail: the sweep deletes rows by query, so a swept message's file
        // stays on disk; an attachment orphan sweep would cover every attachable.
        return RetentionPolicy::window(24, 'created_at',
            'Chat messages between colleagues: who wrote, what they said or sent, '
            .'reactions and when it was read. Kept for two years and then deleted.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Messages colleagues send each other in Chat inside EzBizi: text, '
            .'photos, files and voice notes, reactions and stars. Staff only; never '
            .'sent to a customer and never leaving the organization.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('body', PersonalDataEntry::CONTENT,
                'Whatever one colleague wrote to the others.',
                // Erased, not dropped: the other people in the chat keep the
                // fact that a message arrived (as staff_messages).
                Erasure::erase('Removed')),
            new PersonalDataEntry('sender_id', PersonalDataEntry::IDENTITY,
                'Which colleague wrote the message, shown beside it in the chat.',
                Erasure::keep('A staff record; the key is nulled when the person goes.')),
            new PersonalDataEntry('reactions, starred_by, hidden_for', PersonalDataEntry::ACTIVITY,
                'Which colleagues reacted to, starred or hid (deleted for themselves) a message.',
                Erasure::keep('User ids only; they name nobody once the person is gone.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('sender_id', $subject->getKey())
            : null;
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /**
     * Messages from others this person has not read, in these chats (cleared
     * and deleted ones and system lines left out): the chat list's counts and
     * the app icon's badge.
     */
    public static function unreadFor(string $userId, array $chatIds): Builder
    {
        return static::query()->withoutGlobalScopes()
            ->join('chat_members as m', fn ($j) => $j->on('m.chat_id', '=', 'chat_messages.chat_id')->where('m.user_id', $userId))
            ->whereIn('chat_messages.chat_id', $chatIds)
            ->where(fn ($q) => $q->whereNull('chat_messages.sender_id')->orWhere('chat_messages.sender_id', '!=', $userId))
            ->whereNull('chat_messages.deleted_at')
            ->whereNotIn('chat_messages.kind', ['system'])
            ->whereRaw('chat_messages.created_at > COALESCE(m.read_at, ?)', ['1970-01-01'])
            ->whereRaw('chat_messages.created_at > COALESCE(m.cleared_at, ?)', ['1970-01-01']);
    }
}
