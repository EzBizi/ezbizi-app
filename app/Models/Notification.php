<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody should know about (plan §12).
 *
 * Addressed to a USER, not a role or a franchise. Roles change and assignments
 * change; a notification is about a moment, and re-deriving its audience later
 * would silently change who had been told what.
 */
class Notification extends Model implements HoldsPersonalData
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    // ── §9 data inventory ────────────────────────────────────────

    /*
     * "Notification history" is another of §9's named hiding places, and this
     * table earns the listing for a specific reason: the body is composed from
     * whatever raised it. A rule that says "tell the manager when a customer's
     * details change" writes that customer's details into a row addressed to
     * somebody else — so personal data about A ends up filed under B, where an
     * erasure request about A would never think to look.
     */
    public static function personalDataPurpose(): string
    {
        return 'Things somebody was told, kept so the app can show what they have and have '
            .'not seen. A notification is addressed to one person and is often ABOUT '
            .'another.';
    }

    public static function personalDataRetention(): string
    {
        return 'Kept after being read, so the list is a history rather than a queue. There '
            .'is no automatic expiry today.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::ACTIVITY,
                'Who was told, and — through read_at — whether they have seen it.'),
            new PersonalDataEntry('title, body', PersonalDataEntry::CONTENT,
                'The message. Composed by whatever raised it, so it can contain the name '
                .'or details of a third party rather than of the person it was sent to.'),
        ];
    }

    public const UPDATED_AT = null;   // read_at is the only mutation

    protected $fillable = [
        'organization_id', 'user_id', 'source', 'title', 'body',
        'subject_type', 'subject_id', 'automation_id', 'event_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
