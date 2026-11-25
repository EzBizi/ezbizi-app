<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's place in a chat, and their own settings for it. */
class ChatMember extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'chat_id', 'user_id', 'is_admin', 'delivered_at', 'read_at',
        'cleared_at', 'pinned_at', 'archived_at', 'muted_until', 'marked_unread', 'left_at',
    ];

    // Receipts compare with message created_at to the microsecond.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'marked_unread' => 'boolean',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'cleared_at' => 'datetime',
            'pinned_at' => 'datetime',
            'archived_at' => 'datetime',
            'muted_until' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'Goes with its chat, or with the person (both foreign keys cascade).');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }
}
