<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A conversation put away until a time. Written by InboxController; returned by ezbizi:wake-snoozed-mail. */
class EmailSnooze extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A message snoozed (or woken) is its mailbox's change on the live feed. */
    protected static array $changeParent = ['mailboxes', Mailbox::class, 'mailbox_id'];

    use HasUuids;

    protected $fillable = [
        'mailbox_id', 'message_id', 'thread_id', 'subject', 'from_name', 'from_address', 'snippet', 'until', 'snoozed_by',
    ];

    protected function casts(): array
    {
        return ['until' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A shared-inbox conversation snoozed until a time, with the subject, sender and preview the '
            .'Snoozed folder shows. Deleted when the conversation returns to the Inbox or is unsnoozed.'
        );
    }
}
