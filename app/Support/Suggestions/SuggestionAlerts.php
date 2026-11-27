<?php

namespace App\Support\Suggestions;

use App\Models\Notification;
use App\Models\Suggestion;
use Illuminate\Support\Str;

/**
 * The author's pop-up (Communication ▸ Alerts' path: a Notification with
 * source `alert`, dismissed by marking it read) when their suggestion is
 * approved and when it goes live (Jose, 2026-11-17: "a pop up letting them
 * know Your Suggestion has been approved and Implemented, Please Update").
 *
 * Fired by Suggestion's own `updated` event, so the staff queue, the desk's
 * tinker and anything else that saves the row all tell the author, once per
 * transition. Written directly rather than through NotificationDispatcher
 * for the reason StaffSuggestionsController::tell gives: a staff request has
 * no tenant for the dispatcher to stamp.
 */
final class SuggestionAlerts
{
    /** The live pop-up's body; the client offers Update on a row carrying it. */
    public const UPDATE_BODY = 'Please update EzBizi to see it.';

    public static function approved(Suggestion $row): void
    {
        $note = filled($row->decision_note) ? ' '.trim((string) $row->decision_note) : '';
        self::tell($row, 'Your suggestion was approved: '.self::firstLine($row).'.'.$note, null);
    }

    public static function live(Suggestion $row): void
    {
        $where = filled($row->release_version) ? ' in v'.$row->release_version : '';
        self::tell($row, "Your suggestion is live{$where}: ".self::firstLine($row).'. Please update to see it.', self::UPDATE_BODY);
    }

    private static function firstLine(Suggestion $row): string
    {
        $line = trim(strtok((string) $row->body, "\n") ?: '');

        return rtrim(Str::limit($line, 90, '…'), '.');
    }

    private static function tell(Suggestion $row, string $title, ?string $body): void
    {
        Notification::insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $row->organization_id,
            'user_id' => $row->user_id,
            'source' => 'alert',
            'title' => Str::limit($title, 250, '…'),
            'body' => $body,
            'subject_type' => Suggestion::class,
            'subject_id' => $row->id,
            'created_at' => now(),
        ]);
    }
}
