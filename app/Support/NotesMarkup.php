<?php

namespace App\Support;

/**
 * Notes may carry <b>, <i>, <u> (client/lib/widgets/formatted_notes.dart).
 * Anything that sends a note out of the app - SMS, email, export, PDF - sends
 * plain(): the text without those three tags. Plain old notes pass unchanged.
 */
final class NotesMarkup
{
    public static function plain(?string $notes): ?string
    {
        return $notes === null ? null : preg_replace('#</?[biu]>#', '', $notes);
    }
}
