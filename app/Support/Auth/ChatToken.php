<?php

namespace App\Support\Auth;

use App\Models\Attachment;
use Illuminate\Http\Request;

/**
 * The EzChat token (Jose, 11-27: "for EzChat, no need to use authenticator to
 * log in there"). EzChat signs in with email and password only, so what it gets
 * back is a chat-only token: chat, calls, its own push device, /me and chat
 * files. It never reaches the CRM; RestrictChatTokens refuses everything else.
 */
final class ChatToken
{
    public const MARKER = 'ezchat';

    public const ABILITIES = [self::MARKER];

    /** Paths a chat token may call; anything else is refused. */
    private const ALLOWED = [
        'api/*/chat', 'api/*/chat/*',
        'api/*/me', 'api/*/me/preferences',
        'api/*/auth/*',
        'api/*/notifications/devices',
        'api/*/users/*/avatar',
        'api/*/attachments/*',
    ];

    public static function is(mixed $token): bool
    {
        return is_object($token) && in_array(self::MARKER, (array) ($token->abilities ?? []), true);
    }

    public static function allows(Request $request): bool
    {
        if (! $request->is(...self::ALLOWED)) {
            return false;
        }
        // Files: chat files only, and only to read.
        if ($request->is('api/*/attachments/*')) {
            $id = $request->route('attachment');
            $id = is_object($id) ? $id->getKey() : $id;

            return $request->isMethod('GET') && Attachment::query()->whereKey($id)
                ->where('attachable_type', 'chat_messages')->exists();
        }

        return true;
    }
}
